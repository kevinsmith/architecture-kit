package sqlite

import (
	"context"
	"database/sql"
	"errors"

	"example.com/architecture/invoice/access"
)

type Grants struct{ db *sql.DB }

func New(db *sql.DB) Grants { return Grants{db: db} }

func (g Grants) CreateSchema(ctx context.Context) error {
	_, err := g.db.ExecContext(ctx, `CREATE TABLE IF NOT EXISTS access_grants (
actor TEXT NOT NULL, permission TEXT NOT NULL, organization TEXT NOT NULL,
PRIMARY KEY (actor, permission, organization))`)
	return err
}

func (g Grants) Find(ctx context.Context, actor, permission, organization string) (access.Grant, bool, error) {
	var grant access.Grant
	err := g.db.QueryRowContext(ctx, `SELECT actor, permission, organization FROM access_grants
WHERE actor = ? AND permission = ? AND organization = ?`, actor, permission, organization).Scan(
		&grant.Actor, &grant.Permission, &grant.Organization)
	if errors.Is(err, sql.ErrNoRows) {
		return access.Grant{}, false, nil
	}
	return grant, err == nil, err
}
