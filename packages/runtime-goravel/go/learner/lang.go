package learner

import (
	"os"
	"strings"
)

// Les messages du runtime suivent la langue de la page du playground : le runtime la passe au processus dans
// FORELSE_LANG (packages/runtime-goravel/src/project.ts). Le français par défaut, comme le reste du moteur.

// English : la page est en anglais (/en).
func English() bool {
	return strings.HasPrefix(strings.ToLower(os.Getenv("FORELSE_LANG")), "en")
}

// T : le texte dans la langue de la page, français ou anglais.
func T(fr, en string) string {
	if English() {
		return en
	}
	return fr
}
