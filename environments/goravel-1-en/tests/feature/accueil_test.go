package feature

import (
	"testing"

	"goravel/tests"
)

func TestHomeResponds(t *testing.T) {
	response, err := tests.Http(t).Get("/")
	if err != nil {
		t.Fatal(err)
	}
	response.AssertOk().AssertSee([]string{"Goravel"})
}

func TestHelloGreetsSomeone(t *testing.T) {
	response, err := tests.Http(t).Get("/hello/Ada")
	if err != nil {
		t.Fatal(err)
	}
	response.AssertOk().AssertJson(map[string]any{"message": "Hello Ada"})
}
