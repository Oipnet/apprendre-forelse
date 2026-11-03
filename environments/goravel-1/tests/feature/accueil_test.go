package feature

import (
	"testing"

	"goravel/tests"
)

func TestAccueilRepond(t *testing.T) {
	response, err := tests.Http(t).Get("/")
	if err != nil {
		t.Fatal(err)
	}
	response.AssertOk().AssertSee([]string{"Goravel"})
}

func TestBonjourSaluePersonne(t *testing.T) {
	response, err := tests.Http(t).Get("/bonjour/Ada")
	if err != nil {
		t.Fatal(err)
	}
	response.AssertOk().AssertJson(map[string]any{"message": "Bonjour Ada"})
}
