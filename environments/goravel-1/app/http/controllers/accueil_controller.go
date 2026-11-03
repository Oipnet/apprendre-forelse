package controllers

import (
	"github.com/goravel/framework/contracts/http"
)

type AccueilController struct{}

func NewAccueilController() *AccueilController {
	return &AccueilController{}
}

func (r *AccueilController) Bonjour(ctx http.Context) http.Response {
	return ctx.Response().Success().Json(http.Json{
		"message": "Bonjour " + ctx.Request().Route("nom"),
	})
}
