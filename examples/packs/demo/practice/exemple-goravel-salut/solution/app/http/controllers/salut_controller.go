package controllers

import (
	"github.com/goravel/framework/contracts/http"
)

type SalutController struct{}

func NewSalutController() *SalutController {
	return &SalutController{}
}

// Salut répond à GET /salut/{nom}.
func (r *SalutController) Salut(ctx http.Context) http.Response {
	nom := ctx.Request().Route("nom")
	if len(nom) < 2 {
		return ctx.Response().Json(422, http.Json{
			"erreur": "prénom trop court",
		})
	}

	return ctx.Response().Success().Json(http.Json{
		"message": "Salut " + nom + " !",
	})
}
