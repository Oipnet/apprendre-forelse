package bootstrap

import "github.com/goravel/framework/contracts/database/schema"

// Migrations : aucune côté framework. Le squelette déclare celle de la table des jobs (file d'attente en
// base), inutile dans le navigateur où la file est synchrone.
func Migrations() []schema.Migration {
	return nil
}
