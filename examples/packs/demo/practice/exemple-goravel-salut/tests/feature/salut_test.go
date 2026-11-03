package feature

import (
	"testing"

	"goravel/tests"
)

func TestSalutUtiliseLePrenom(t *testing.T) {
	response, err := tests.Http(t).Get("/salut/Ada")
	if err != nil {
		t.Fatal(err)
	}
	response.AssertOk().AssertExactJson(map[string]any{"message": "Salut Ada !"})
}

func TestSalutRefuseUnPrenomTropCourt(t *testing.T) {
	response, err := tests.Http(t).Get("/salut/A")
	if err != nil {
		t.Fatal(err)
	}
	response.AssertUnprocessableEntity().AssertExactJson(map[string]any{"erreur": "prénom trop court"})
}
