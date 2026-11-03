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
	return ctx.Response().Success().Json(http.Json{
		"message": "à compléter",
	})
}
