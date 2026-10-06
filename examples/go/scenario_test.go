package invoice_test

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"net/url"
	"path/filepath"
	"slices"
	"sync"
	"testing"
	"time"

	"example.com/architecture/invoice/assembly"
	"example.com/architecture/invoice/billing"
	"example.com/architecture/invoice/billing/contracts"
	"example.com/architecture/invoice/billing/domain"
	billingdb "example.com/architecture/invoice/billing/sqlite"
	"example.com/architecture/invoice/notifications"
	"example.com/architecture/invoice/notifications/input"
)

type fixedClock struct{ value time.Time }

func (f fixedClock) Now() time.Time { return f.value }

func instant() time.Time { return time.Date(2026, 10, 4, 12, 0, 0, 0, time.UTC) }

type mailbox struct {
	mu       sync.Mutex
	failures int
	messages []notifications.Message
}

func (m *mailbox) Send(_ context.Context, message notifications.Message) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.failures > 0 {
		m.failures--
		return errors.New("mail unavailable")
	}
	m.messages = append(m.messages, message)
	return nil
}

func (m *mailbox) snapshot() []notifications.Message {
	m.mu.Lock()
	defer m.mu.Unlock()
	return append([]notifications.Message(nil), m.messages...)
}

// Raw SQL and an adapter over it are test-only facilities for seeding, inspection,
// and injecting database failures. Composition exposes neither.
type testRuntime struct {
	*assembly.Runtime
	DB       *sql.DB
	Invoices billingdb.Store
}

func (r *testRuntime) Close() error {
	return errors.Join(r.Runtime.Close(), r.DB.Close())
}

func open(t *testing.T, path string, mail *mailbox) *testRuntime {
	t.Helper()
	runtime, err := assembly.Open(path, mail, fixedClock{instant()})
	if err != nil {
		t.Fatal(err)
	}
	location := url.URL{Scheme: "file", Path: path, RawQuery: "_pragma=foreign_keys(1)&_pragma=busy_timeout(5000)"}
	db, err := sql.Open("sqlite", location.String())
	if err != nil {
		runtime.Close()
		t.Fatal(err)
	}
	fixture := &testRuntime{Runtime: runtime, DB: db, Invoices: billingdb.New(db)}
	t.Cleanup(func() { fixture.Close() })
	return fixture
}

func fixture(t *testing.T) (*testRuntime, *mailbox, string) {
	t.Helper()
	path := filepath.Join(t.TempDir(), "invoice.db")
	mail := &mailbox{}
	runtime := open(t, path, mail)
	seed(t, runtime, "inv", "org-a", "alice")
	return runtime, mail, path
}

func seed(t *testing.T, runtime *testRuntime, id, organization, actor string) {
	t.Helper()
	_, err := runtime.DB.Exec(`INSERT INTO billing_invoices VALUES (?, ?, 'open', ?, 0, ?)`,
		id, organization, instant().Add(time.Hour).UnixNano(), id+"@example.test")
	if err != nil {
		t.Fatal(err)
	}
	_, err = runtime.DB.Exec(`INSERT OR IGNORE INTO access_grants VALUES (?, ?, ?)`, actor, contracts.VoidInvoice, organization)
	if err != nil {
		t.Fatal(err)
	}
}

func command() billing.Command { return billing.Command{InvoiceID: "inv", OrganizationID: "org-a"} }

func assertState(t *testing.T, runtime *testRuntime, status domain.Status, intents int) {
	t.Helper()
	invoice, found, err := runtime.Invoices.Load(context.Background(), "inv")
	if err != nil || !found || invoice.Status != status {
		t.Fatalf("invoice = %+v, found = %v, err = %v; want %s", invoice, found, err, status)
	}
	var count int
	if err := runtime.DB.QueryRow(`SELECT COUNT(*) FROM billing_outbox`).Scan(&count); err != nil {
		t.Fatal(err)
	}
	if count != intents {
		t.Fatalf("intents = %d, want %d", count, intents)
	}
}

func event() contracts.InvoiceVoided {
	return contracts.InvoiceVoided{
		ID: "inv:void:1", InvoiceID: "inv", OrganizationID: "org-a", OccurredAt: instant().UnixNano(),
		Recipients: []contracts.Recipient{{Address: "inv@example.test"}},
	}
}

func tentative(ctx context.Context, tx billing.Transaction) error {
	before := domain.Invoice{ID: "inv", OrganizationID: "org-a", Status: domain.Open, Version: 0}
	next := before
	next.Status, next.Version = domain.Voided, 1
	saved, err := tx.Save(ctx, before, next)
	if err != nil {
		return err
	}
	if !saved {
		return errors.New("fixture write unexpectedly conflicted")
	}
	return tx.AddEvent(ctx, event())
}

func TestV01DomainUsesExplicitInputs(t *testing.T) {
	invoice := domain.Invoice{ID: "inv", Status: domain.Open, Deadline: instant().Add(time.Hour)}
	first, decision := domain.Void(invoice, instant())
	second, again := domain.Void(invoice, instant())
	if decision != nil || again != nil || first != second || invoice.Status != domain.Open {
		t.Fatal("domain decision changed or mutated its value input")
	}
	if _, decision := domain.Void(invoice, invoice.Deadline); !errors.Is(decision, domain.ErrExpired) {
		t.Fatalf("deadline boundary decision = %s", decision)
	}
}

func TestV02V04V10SuccessRepeatAndPushDelivery(t *testing.T) {
	runtime, mail, _ := fixture(t)
	err := runtime.Billing.Void(context.Background(), "alice", command())
	if err != nil {
		t.Fatal(err)
	}
	assertState(t, runtime, domain.Voided, 1)
	if len(mail.snapshot()) != 0 {
		t.Fatal("use case performed external delivery")
	}
	err = runtime.Billing.Void(context.Background(), "alice", command())
	if !errors.Is(err, billing.ErrAlreadyVoided) {
		t.Fatalf("repeat error = %v", err)
	}
	assertState(t, runtime, domain.Voided, 1)
	if err := runtime.Dispatcher.Dispatch(context.Background()); err != nil {
		t.Fatal(err)
	}
	messages := mail.snapshot()
	if len(messages) != 1 || messages[0].Recipient != "inv@example.test" || messages[0].EventID != "inv:void:1" || messages[0].Body != "Invoice inv was voided." {
		t.Fatalf("wrong push-shaped delivery: %+v", messages)
	}
}

func TestV03ExpectedRejections(t *testing.T) {
	cases := []struct {
		name, actor, scope, status string
		deadline                   time.Time
		missing                    bool
		want                       error
	}{
		{"grant", "bob", "org-a", "open", instant().Add(time.Hour), false, billing.ErrForbidden},
		{"anonymous", "", "org-a", "open", instant().Add(time.Hour), false, billing.ErrForbidden},
		{"scope", "alice", "org-b", "open", instant().Add(time.Hour), false, billing.ErrForbidden},
		{"settled", "alice", "org-a", "settled", instant().Add(time.Hour), false, billing.ErrSettled},
		{"already-voided", "alice", "org-a", "voided", instant().Add(time.Hour), false, billing.ErrAlreadyVoided},
		{"expired", "alice", "org-a", "open", instant(), false, billing.ErrExpired},
		{"invalid", "alice", "org-a", "broken", instant().Add(time.Hour), false, billing.ErrInvalidState},
		{"absent", "alice", "org-a", "open", instant().Add(time.Hour), true, billing.ErrNotFound},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			runtime, _, _ := fixture(t)
			if tc.name == "scope" {
				// Permission in the claimed scope must not authorize a resource from another scope.
				if _, err := runtime.DB.Exec(`INSERT INTO access_grants VALUES ('alice', ?, 'org-b')`, contracts.VoidInvoice); err != nil {
					t.Fatal(err)
				}
			}
			if _, err := runtime.DB.Exec(`UPDATE billing_invoices SET status = ?, deadline = ?`, tc.status, tc.deadline.UnixNano()); err != nil {
				t.Fatal(err)
			}
			cmd := billing.Command{InvoiceID: "inv", OrganizationID: tc.scope}
			if tc.missing {
				cmd.InvoiceID = "missing"
			}
			err := runtime.Billing.Void(context.Background(), tc.actor, cmd)
			if !errors.Is(err, tc.want) {
				t.Fatalf("error = %v; want %v", err, tc.want)
			}
			assertState(t, runtime, domain.Status(tc.status), 0)
		})
	}
}

func TestV05ExplicitAbortAndUnexpectedFailure(t *testing.T) {
	for _, mode := range []string{"expected", "unexpected", "panic", "cancel"} {
		t.Run(mode, func(t *testing.T) {
			runtime, _, _ := fixture(t)
			ctx, cancel := context.WithCancel(context.Background())
			defer cancel()
			injected := errors.New("injected failure")
			var err error
			var recovered any
			func() {
				defer func() { recovered = recover() }()
				err = runtime.Invoices.Run(ctx, func(tx billing.Transaction) error {
					if err := tentative(ctx, tx); err != nil {
						return err
					}
					switch mode {
					case "expected":
						return fmt.Errorf("abort after write: %w", billing.ErrConflict)
					case "unexpected":
						return fmt.Errorf("storage failure: %w", injected)
					case "panic":
						panic(injected)
					case "cancel":
						cancel()
					}
					return nil
				})
			}()
			if mode == "expected" && !errors.Is(err, billing.ErrConflict) {
				t.Fatalf("expected abort lost classification: %v", err)
			}
			if mode == "unexpected" && (!errors.Is(err, injected) || errors.Is(err, billing.ErrConflict)) {
				t.Fatalf("operational failure lost identity: %v", err)
			}
			if mode == "panic" && recovered != injected {
				t.Fatalf("panic was swallowed: %v", recovered)
			}
			if mode != "panic" && err == nil {
				t.Fatal("failure reported success")
			}
			assertState(t, runtime, domain.Open, 0)
		})
	}
}

func TestV06WriteOutboxAndCommitFailuresAreAtomic(t *testing.T) {
	cases := map[string]string{
		"state":  `CREATE TRIGGER fail_state BEFORE UPDATE ON billing_invoices BEGIN SELECT RAISE(ABORT, 'state failure'); END`,
		"outbox": `CREATE TRIGGER fail_outbox BEFORE INSERT ON billing_outbox BEGIN SELECT RAISE(ABORT, 'outbox failure'); END`,
		"commit": `CREATE TABLE commit_parent (id INTEGER PRIMARY KEY);
CREATE TABLE commit_child (parent INTEGER REFERENCES commit_parent(id) DEFERRABLE INITIALLY DEFERRED);
CREATE TRIGGER fail_commit AFTER INSERT ON billing_outbox BEGIN INSERT INTO commit_child VALUES (999); END`,
	}
	for name, setup := range cases {
		t.Run(name, func(t *testing.T) {
			runtime, mail, _ := fixture(t)
			if _, err := runtime.DB.Exec(setup); err != nil {
				t.Fatal(err)
			}
			err := runtime.Billing.Void(context.Background(), "alice", command())
			if err == nil {
				t.Fatalf("failed %s reported success", name)
			}
			assertState(t, runtime, domain.Open, 0)
			if len(mail.snapshot()) != 0 {
				t.Fatal("delivery occurred on failed transaction")
			}
			if _, err := runtime.DB.Exec("DROP TRIGGER fail_" + name); err != nil {
				t.Fatal(err)
			}
			if err := runtime.Billing.Void(context.Background(), "alice", command()); err != nil {
				t.Fatalf("connection pool unusable after %s failure: %v", name, err)
			}
		})
	}
}

func TestV07UncommittedVisibilityAndDurableRetry(t *testing.T) {
	runtime, mail, path := fixture(t)
	inserted, release := make(chan struct{}), make(chan struct{})
	done := make(chan error, 1)
	go func() {
		err := runtime.Invoices.Run(context.Background(), func(tx billing.Transaction) error {
			if err := tentative(context.Background(), tx); err != nil {
				return err
			}
			close(inserted)
			<-release
			return nil
		})
		done <- err
	}()
	var once sync.Once
	unblock := func() { once.Do(func() { close(release) }) }
	defer unblock()
	select {
	case <-inserted:
	case err := <-done:
		t.Fatalf("transaction ended before visibility check: %v", err)
	}
	pending, err := runtime.Invoices.Pending(context.Background())
	if err != nil || len(pending) != 0 {
		t.Fatalf("uncommitted intent visible: %+v, %v", pending, err)
	}
	unblock()
	if err := <-done; err != nil {
		t.Fatal(err)
	}
	if err := runtime.Close(); err != nil {
		t.Fatal(err)
	}
	runtime = open(t, path, mail)
	mail.failures = 1
	if err := runtime.Dispatcher.Dispatch(context.Background()); err == nil {
		t.Fatal("notification failure was swallowed")
	}
	assertState(t, runtime, domain.Voided, 1)
	pending, err = runtime.Invoices.Pending(context.Background())
	if err != nil || len(pending) != 1 {
		t.Fatalf("retry intent lost: %+v, %v", pending, err)
	}
	if err := runtime.Dispatcher.Dispatch(context.Background()); err != nil {
		t.Fatal(err)
	}
	pending, err = runtime.Invoices.Pending(context.Background())
	if err != nil || len(pending) != 0 || len(mail.snapshot()) != 1 {
		t.Fatalf("retry failed: pending = %d, err = %v", len(pending), err)
	}
}

func TestV07AcknowledgmentFailurePermitsDuplicateDelivery(t *testing.T) {
	runtime, mail, _ := fixture(t)
	if err := runtime.Billing.Void(context.Background(), "alice", command()); err != nil {
		t.Fatal(err)
	}
	if _, err := runtime.DB.Exec(`CREATE TRIGGER fail_ack BEFORE UPDATE ON billing_outbox BEGIN SELECT RAISE(ABORT, 'ack failure'); END`); err != nil {
		t.Fatal(err)
	}
	if err := runtime.Dispatcher.Dispatch(context.Background()); err == nil {
		t.Fatal("acknowledgment failure was swallowed")
	}
	if _, err := runtime.DB.Exec(`DROP TRIGGER fail_ack`); err != nil {
		t.Fatal(err)
	}
	if err := runtime.Dispatcher.Dispatch(context.Background()); err != nil {
		t.Fatal(err)
	}
	if len(mail.snapshot()) != 2 {
		t.Fatal("fixture must demonstrate at-least-once, not promise exactly-once delivery")
	}
	assertState(t, runtime, domain.Voided, 1)
}

func deliveryBatch(t *testing.T) (*testRuntime, *mailbox, []string) {
	t.Helper()
	mail := &mailbox{}
	runtime := open(t, filepath.Join(t.TempDir(), "invoice.db"), mail)
	var ids []string
	for _, id := range []string{"inv-a", "inv-b", "inv-c"} {
		seed(t, runtime, id, "org-a", "alice")
		if err := runtime.Billing.Void(context.Background(), "alice", billing.Command{
			InvoiceID: id, OrganizationID: "org-a",
		}); err != nil {
			t.Fatal(err)
		}
		ids = append(ids, id+":void:1")
	}
	return runtime, mail, ids
}

func assertBatchDelivery(t *testing.T, runtime *testRuntime, mail *mailbox, wantMessages, wantPending []string) {
	t.Helper()
	var messages []string
	for _, message := range mail.snapshot() {
		messages = append(messages, message.EventID)
	}
	if !slices.Equal(messages, wantMessages) {
		t.Fatalf("delivered event IDs = %v, want %v", messages, wantMessages)
	}
	// Inspect raw rows even when a poisoned payload prevents Pending from decoding them.
	rows, err := runtime.DB.Query(`SELECT id FROM billing_outbox WHERE delivered = 0 ORDER BY id`)
	if err != nil {
		t.Fatal(err)
	}
	defer rows.Close()
	var pending []string
	for rows.Next() {
		var id string
		if err := rows.Scan(&id); err != nil {
			t.Fatal(err)
		}
		pending = append(pending, id)
	}
	if err := rows.Err(); err != nil {
		t.Fatal(err)
	}
	if !slices.Equal(pending, wantPending) {
		t.Fatalf("pending event IDs = %v, want %v", pending, wantPending)
	}
	var intents, voided int
	if err := runtime.DB.QueryRow(`SELECT COUNT(*) FROM billing_outbox`).Scan(&intents); err != nil {
		t.Fatal(err)
	}
	if err := runtime.DB.QueryRow(`SELECT COUNT(*) FROM billing_invoices WHERE status = 'voided' AND version = 1`).Scan(&voided); err != nil {
		t.Fatal(err)
	}
	if intents != 3 || voided != 3 {
		t.Fatalf("dispatch changed committed work: intents = %d, voided = %d; want 3 each", intents, voided)
	}
}

func TestV07InvalidPayloadBlocksBatchUntilRepaired(t *testing.T) {
	for _, tc := range []struct {
		name, payload string
	}{
		{"decode", `{"id":`},
		{"validation", `{"id":"inv-b:void:1"}`},
	} {
		t.Run(tc.name, func(t *testing.T) {
			runtime, mail, ids := deliveryBatch(t)
			var original string
			if err := runtime.DB.QueryRow(`SELECT payload FROM billing_outbox WHERE id = ?`, ids[1]).Scan(&original); err != nil {
				t.Fatal(err)
			}
			if _, err := runtime.DB.Exec(`UPDATE billing_outbox SET payload = ? WHERE id = ?`, tc.payload, ids[1]); err != nil {
				t.Fatal(err)
			}
			for attempt := 0; attempt < 2; attempt++ {
				if err := runtime.Dispatcher.Dispatch(context.Background()); err == nil {
					t.Fatal("invalid stored payload was accepted or silently skipped")
				}
				assertBatchDelivery(t, runtime, mail, nil, ids)
			}
			if _, err := runtime.DB.Exec(`UPDATE billing_outbox SET payload = ? WHERE id = ?`, original, ids[1]); err != nil {
				t.Fatal(err)
			}
			if err := runtime.Dispatcher.Dispatch(context.Background()); err != nil {
				t.Fatal(err)
			}
			assertBatchDelivery(t, runtime, mail, ids, nil)
		})
	}
}

type failingSubscriber struct {
	billing.Subscriber
	eventID string
	failure error
}

func (n failingSubscriber) Deliver(ctx context.Context, event contracts.InvoiceVoided) error {
	if event.ID == n.eventID {
		return n.failure
	}
	return n.Subscriber.Deliver(ctx, event)
}

func TestV07PersistentDeliveryAndAcknowledgmentFailuresBlockLaterIntents(t *testing.T) {
	for _, mode := range []string{"delivery", "acknowledgment"} {
		t.Run(mode, func(t *testing.T) {
			runtime, mail, ids := deliveryBatch(t)
			original := runtime.Dispatcher.Subscriber
			permanent := errors.New("permanent delivery failure")
			if mode == "delivery" {
				runtime.Dispatcher.Subscriber = failingSubscriber{Subscriber: original, eventID: ids[1], failure: permanent}
			} else if _, err := runtime.DB.Exec(`CREATE TRIGGER fail_batch_ack BEFORE UPDATE OF delivered ON billing_outbox
WHEN OLD.id = 'inv-b:void:1' BEGIN SELECT RAISE(ABORT, 'ack failure'); END`); err != nil {
				t.Fatal(err)
			}
			messages := []string{ids[0]}
			for attempt := 0; attempt < 2; attempt++ {
				err := runtime.Dispatcher.Dispatch(context.Background())
				if err == nil || (mode == "delivery" && !errors.Is(err, permanent)) {
					t.Fatalf("%s failure was swallowed or changed: %v", mode, err)
				}
				if mode == "acknowledgment" {
					messages = append(messages, ids[1])
				}
				assertBatchDelivery(t, runtime, mail, messages, ids[1:])
			}
			if mode == "delivery" {
				runtime.Dispatcher.Subscriber = original
			} else if _, err := runtime.DB.Exec(`DROP TRIGGER fail_batch_ack`); err != nil {
				t.Fatal(err)
			}
			if err := runtime.Dispatcher.Dispatch(context.Background()); err != nil {
				t.Fatal(err)
			}
			assertBatchDelivery(t, runtime, mail, append(messages, ids[1], ids[2]), nil)
		})
	}
}

type heldStore struct {
	billing.Store
	loaded  chan struct{}
	release chan struct{}
}

func (s heldStore) Load(ctx context.Context, id string) (domain.Invoice, bool, error) {
	invoice, found, err := s.Store.Load(ctx, id)
	close(s.loaded)
	<-s.release
	return invoice, found, err
}

func TestV08ConcurrentSettlementCannotBeOverwritten(t *testing.T) {
	runtime, _, _ := fixture(t)
	store := heldStore{Store: runtime.Invoices, loaded: make(chan struct{}), release: make(chan struct{})}
	service := billing.New(store, runtime.Access, fixedClock{instant()})
	results := make(chan error, 1)
	go func() {
		results <- service.Void(context.Background(), "alice", command())
	}()
	<-store.loaded
	var once sync.Once
	unblock := func() { once.Do(func() { close(store.release) }) }
	defer unblock()
	if _, err := runtime.DB.Exec(`UPDATE billing_invoices SET status = 'settled', version = version + 1 WHERE id = 'inv' AND status = 'open' AND version = 0`); err != nil {
		t.Fatal(err)
	}
	unblock()
	if err := <-results; !errors.Is(err, billing.ErrConflict) {
		t.Fatalf("stale decision overwrote settlement: %v", err)
	}
	assertState(t, runtime, domain.Settled, 0)
}

func TestV08SettlementCannotOverwriteCompletedVoid(t *testing.T) {
	runtime, _, _ := fixture(t)
	if err := runtime.Billing.Void(context.Background(), "alice", command()); err != nil {
		t.Fatal(err)
	}
	result, err := runtime.DB.Exec(`UPDATE billing_invoices SET status = 'settled', version = version + 1 WHERE id = 'inv' AND status = 'open' AND version = 0`)
	if err != nil {
		t.Fatal(err)
	}
	count, err := result.RowsAffected()
	if err != nil || count != 0 {
		t.Fatalf("stale settlement overwrote void: %d, %v", count, err)
	}
	assertState(t, runtime, domain.Voided, 1)
}

func TestV09ConcurrentActorsAndTransactionsAreIsolated(t *testing.T) {
	runtime, _, _ := fixture(t)
	const count = 12
	for i := 0; i < count; i++ {
		seed(t, runtime, fmt.Sprintf("inv-%d", i), fmt.Sprintf("org-%d", i), fmt.Sprintf("actor-%d", i))
	}
	var wg sync.WaitGroup
	for i := 0; i < count; i++ {
		wg.Add(1)
		go func(i int) {
			defer wg.Done()
			actor := fmt.Sprintf("actor-%d", i)
			var want error
			if i%2 == 1 {
				actor, want = "alice", billing.ErrForbidden
			}
			err := runtime.Billing.Void(context.Background(), actor, billing.Command{
				InvoiceID: fmt.Sprintf("inv-%d", i), OrganizationID: fmt.Sprintf("org-%d", i),
			})
			if !errors.Is(err, want) {
				t.Errorf("invocation %d: %v; want %v", i, err, want)
			}
		}(i)
	}
	wg.Wait()
	for i := 0; i < count; i++ {
		invoice, found, err := runtime.Invoices.Load(context.Background(), fmt.Sprintf("inv-%d", i))
		want := domain.Voided
		if i%2 == 1 {
			want = domain.Open
		}
		if err != nil || !found || invoice.Status != want || invoice.OrganizationID != fmt.Sprintf("org-%d", i) {
			t.Errorf("invocation %d affected the wrong state: %+v, %v", i, invoice, err)
		}
	}
	var intents int
	if err := runtime.DB.QueryRow(`SELECT COUNT(*) FROM billing_outbox`).Scan(&intents); err != nil || intents != count/2 {
		t.Fatalf("isolated intents = %d, %v", intents, err)
	}
}

func TestV11TypedCollectionAndRuntimeSchema(t *testing.T) {
	valid := `{"id":"e","invoice_id":"inv","organization_id":"org-a","occurred_at":"1791115200123456789","recipients":[{"address":"billing@example.test"}]}`
	decoded, err := input.Decode([]byte(valid))
	if err != nil || len(decoded.Recipients) != 1 || decoded.Recipients[0].Address != "billing@example.test" {
		t.Fatalf("typed collection rejected: %+v, %v", decoded, err)
	}
	encoded, err := json.Marshal(decoded)
	if err != nil {
		t.Fatal(err)
	}
	again, err := input.Decode(encoded)
	if err != nil || again.OccurredAt != 1791115200123456789 {
		t.Fatalf("timestamp precision lost: %+v, %v", again, err)
	}
	for _, payload := range []string{
		`{"payload":{"arbitrary":"shape"}}`,
		`{"id":"e","invoice_id":"inv","organization_id":"org-a","occurred_at":"1","recipients":[{"address":42}]}`,
		`{"id":"e","invoice_id":"inv","organization_id":"org-a","occurred_at":"1","recipients":[{"address":""}]}`,
		`{"id":"e","invoice_id":"inv","organization_id":"org-a","occurred_at":"1","recipients":[]}`,
		`{"id":"e","invoice_id":"inv","organization_id":"org-a","occurred_at":"1","recipients":[{"address":"x","unknown":true}]}`,
		`{"id":"e","invoice_id":"inv","organization_id":"org-a","occurred_at":1791115200123456789,"recipients":[{"address":"x"}]}`,
		valid + `{}`,
		`null`,
	} {
		if _, err := input.Decode([]byte(payload)); err == nil {
			t.Errorf("invalid representation accepted: %s", payload)
		}
	}
}
