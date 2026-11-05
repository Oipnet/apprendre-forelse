// Package learner : le code de l'apprenant, interprété par yaegi.
//
// Le framework, la configuration et les façades sont compilés dans le binaire. Les dossiers que l'apprenant
// modifie (routes, contrôleurs, modèles, tests) sont relus à chaque démarrage : une modification ne demande
// pas de recompiler, seulement de relancer une instance du module WebAssembly (environ une seconde).
//
// Le projet est monté en GOPATH/src/goravel (le nom du module du squelette) : un import
// "goravel/app/http/controllers" dans routes/web.go se résout vers les sources du projet, un import
// "goravel/app/facades" vers les façades compilées (yaegisym), qui ont la priorité.
package learner

import (
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"reflect"
	"sort"
	"strings"
	"testing"

	"github.com/traefik/yaegi/interp"
	"github.com/traefik/yaegi/stdlib"

	"goravel/yaegisym"
)

// Root : la racine du projet, sous GOPATH/src.
const (
	GoPath = "/go"
	Module = "goravel"
	Root   = GoPath + "/src/" + Module
)

// Interpreter : un interpréteur par instance, partagé par les routes et les tests (mêmes types).
type Interpreter struct {
	i *interp.Interpreter
}

func New() (*Interpreter, error) {
	i := interp.New(interp.Options{GoPath: GoPath, Env: os.Environ()})
	if err := i.Use(stdlib.Symbols); err != nil {
		return nil, err
	}
	if err := i.Use(yaegisym.Symbols); err != nil {
		return nil, err
	}
	return &Interpreter{i: i}, nil
}

// Routes : interprète le paquet routes et appelle ses fonctions d'enregistrement (Web, puis Api si elle existe).
func (r *Interpreter) Routes() (err error) {
	defer recoverInto(&err)
	if _, err := os.Stat(filepath.Join(Root, "routes")); err != nil {
		return nil
	}
	if _, err := r.i.Eval(`import "goravel/routes"`); err != nil {
		return Clean(err)
	}
	for _, name := range []string{"Web", "Api"} {
		v, err := r.i.Eval("routes." + name)
		if err != nil {
			continue
		}
		f, ok := v.Interface().(func())
		if !ok {
			return fmt.Errorf("routes.%s doit être une fonction sans argument : func %s()", name, name)
		}
		f()
	}
	return nil
}

// TestDirs : les dossiers du projet qui contiennent des fichiers _test.go, relatifs à la racine.
func TestDirs() []string {
	seen := map[string]bool{}
	_ = filepath.WalkDir(Root, func(path string, d fs.DirEntry, err error) error {
		if err != nil {
			return nil
		}
		if d.IsDir() && (d.Name() == "storage" || d.Name() == "vendor" || strings.HasPrefix(d.Name(), ".")) && path != Root {
			return filepath.SkipDir
		}
		if !d.IsDir() && strings.HasSuffix(d.Name(), "_test.go") {
			rel, _ := filepath.Rel(Root, filepath.Dir(path))
			seen[filepath.ToSlash(rel)] = true
		}
		return nil
	})
	dirs := make([]string, 0, len(seen))
	for dir := range seen {
		dirs = append(dirs, dir)
	}
	sort.Strings(dirs)
	return dirs
}

// Test : une fonction TestXxx, avec le fichier qui la déclare.
type Test struct {
	Name string
	File string
	Func func(*testing.T)
}

// Tests : interprète un dossier de tests et rend ses fonctions TestXxx, dans l'ordre des noms.
func (r *Interpreter) Tests(dir string) (tests []Test, err error) {
	defer recoverInto(&err)
	path := Module + "/" + dir
	if dir == "." {
		path = Module
	}
	if err := r.i.EvalTest(path); err != nil {
		return nil, Clean(err)
	}
	files := declaringFiles(filepath.Join(Root, dir))
	for _, symbols := range r.i.Symbols(path) {
		for name, value := range symbols {
			if !strings.HasPrefix(name, "Test") || !value.IsValid() || value.Kind() != reflect.Func {
				continue
			}
			if f, ok := value.Interface().(func(*testing.T)); ok {
				tests = append(tests, Test{Name: name, File: files[name], Func: f})
			}
		}
	}
	sort.Slice(tests, func(a, b int) bool { return tests[a].Name < tests[b].Name })
	return tests, nil
}

// declaringFiles : nom d'une fonction TestXxx => fichier qui la déclare (relatif au projet), lu dans les sources.
func declaringFiles(dir string) map[string]string {
	found := map[string]string{}
	entries, _ := os.ReadDir(dir)
	for _, entry := range entries {
		if !strings.HasSuffix(entry.Name(), "_test.go") {
			continue
		}
		src, err := os.ReadFile(filepath.Join(dir, entry.Name()))
		if err != nil {
			continue
		}
		rel, _ := filepath.Rel(Root, filepath.Join(dir, entry.Name()))
		for _, line := range strings.Split(string(src), "\n") {
			if rest, ok := strings.CutPrefix(line, "func Test"); ok {
				name := "Test" + strings.SplitN(rest, "(", 2)[0]
				found[strings.TrimSpace(name)] = filepath.ToSlash(rel)
			}
		}
	}
	return found
}

// Clean : une erreur de l'interpréteur, avec des chemins relatifs au projet (routes/web.go:12:3 : …).
func Clean(err error) error {
	if err == nil {
		return nil
	}
	return fmt.Errorf("%s", strings.ReplaceAll(err.Error(), Root+"/", ""))
}

func recoverInto(err *error) {
	if p := recover(); p != nil {
		*err = Clean(fmt.Errorf("panic: %v", p))
	}
}
