package access

import (
	"context"

	"example.com/architecture/invoice/access/internal/policy"
)

type Grants interface {
	Find(ctx context.Context, actor, permission, organization string) (policy.Grant, bool, error)
}

type Service struct{ Grants Grants }

func (s Service) Can(ctx context.Context, actor, permission, organization string) (bool, error) {
	grant, found, err := s.Grants.Find(ctx, actor, permission, organization)
	if err != nil || !found {
		return false, err
	}
	return policy.Allows(grant, actor, permission, organization), nil
}
