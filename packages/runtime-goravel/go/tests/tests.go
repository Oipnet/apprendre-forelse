// Package tests : les outils des tests de l'apprenant, compilés dans le binaire.
//
// Dans le squelette Goravel, un test embarque tests.TestCase dans une suite testify. Les suites ne
// marchent pas sous l'interpréteur (testify cherche les méthodes Test… par réflexion, et les types
// interprétés n'en exposent pas) : les tests de l'apprenant sont donc des fonctions TestXxx(t), qui
// passent par Http(t) pour appeler l'application, avec les mêmes assertions que TestCase.Http.
package tests

import (
	"testing"

	frameworktesting "github.com/goravel/framework/testing"

	contractshttp "github.com/goravel/framework/contracts/testing/http"
)

var testCase frameworktesting.TestCase

// Http : une requête vers l'application, comme s.Http(s.T()) dans une suite du squelette.
//
//	tests.Http(t).Get("/")
func Http(t testing.TB) contractshttp.Request {
	return testCase.Http(t)
}
