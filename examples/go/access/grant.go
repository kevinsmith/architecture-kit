package access

type Grant struct {
	Actor        string
	Permission   string
	Organization string
}

func Allows(grant Grant, actor, permission, organization string) bool {
	return actor != "" && grant.Actor == actor && grant.Permission == permission && grant.Organization == organization
}
