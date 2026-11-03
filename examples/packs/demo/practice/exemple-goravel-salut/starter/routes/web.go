package routes

import (
	"github.com/goravel/framework/contracts/http"

	"goravel/app/facades"
	"goravel/app/http/controllers"
)

func Web() {
	facades.Route().Get("/", func(ctx http.Context) http.Response {
		return ctx.Response().View().Make("welcome.tmpl", map[string]any{
			"name": "Goravel",
		})
	})

	accueil := controllers.NewAccueilController()
	facades.Route().Get("/bonjour/{nom}", accueil.Bonjour)

	salut := controllers.NewSalutController()
	facades.Route().Get("/salut/{nom}", salut.Salut)
}
