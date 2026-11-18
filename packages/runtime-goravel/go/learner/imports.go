package learner

import (
	"fmt"
	"go/ast"
	"go/parser"
	"go/token"
	"io/fs"
	"os"
	"path"
	"path/filepath"
	"reflect"
	"regexp"
	"sort"
	"strconv"
	"strings"

	"github.com/traefik/yaegi/stdlib"

	"goravel/yaegisym"
)

// available : les chemins d'import compilés dans le binaire (bibliothèque standard et paquets extraits par
// gen-symbols.sh). Les clés de yaegi sont « chemin/nom » : on retire le nom.
func available(symbols map[string]map[string]reflect.Value) map[string]bool {
	paths := map[string]bool{}
	for key := range symbols {
		paths[path.Dir(key)] = true
	}
	return paths
}

// unavailableImport : le premier import qui ne vient ni de la bibliothèque standard, ni des paquets compilés, ni
// des sources du projet. yaegi le signale (« unable to find source related to »), mais sans dire quoi importer à
// la place : on donne la liste des paquets du framework disponibles.
func unavailableImport() error {
	std, extracted := available(stdlib.Symbols), available(yaegisym.Symbols)
	var err error
	walkSources(func(p string) bool {
		fset := token.NewFileSet()
		f, perr := parser.ParseFile(fset, p, nil, parser.ImportsOnly)
		if perr != nil {
			return true // une erreur de syntaxe : yaegi la signale lui-même
		}
		for _, spec := range f.Imports {
			imp, _ := strconv.Unquote(spec.Path.Value)
			if std[imp] || extracted[imp] {
				continue
			}
			if rest, ok := strings.CutPrefix(imp, Module+"/"); ok {
				if info, serr := os.Stat(filepath.Join(Root, rest)); serr == nil && info.IsDir() {
					continue
				}
			}
			list := make([]string, 0, len(extracted))
			for e := range extracted {
				if !strings.HasPrefix(e, Module+"/") {
					list = append(list, e)
				}
			}
			sort.Strings(list)
			pos := fset.Position(spec.Pos())
			err = Clean(fmt.Errorf("%s:%d:%d: le paquet %q n'est pas disponible dans le navigateur. Paquets disponibles, en plus de la bibliothèque standard, de goravel/app/facades, goravel/tests et des dossiers du projet : %s",
				filepath.ToSlash(pos.Filename), pos.Line, pos.Column, imp, strings.Join(list, ", ")))
			return false
		}
		return true
	})
	return err
}

// walkSources : chaque fichier .go du projet, dans l'ordre des chemins, tant que fn rend true.
func walkSources(fn func(path string) bool) {
	var paths []string
	_ = filepath.WalkDir(Root, func(p string, d fs.DirEntry, err error) error {
		if err != nil {
			return nil
		}
		if d.IsDir() && p != Root && (d.Name() == "storage" || d.Name() == "vendor" || strings.HasPrefix(d.Name(), ".")) {
			return filepath.SkipDir
		}
		if !d.IsDir() && strings.HasSuffix(d.Name(), ".go") {
			paths = append(paths, p)
		}
		return nil
	})
	sort.Strings(paths)
	for _, p := range paths {
		if !fn(p) {
			return
		}
	}
}

// missingImport : le premier `paquet.Nom` dont le paquet n'est pas importé, dans les sources de l'apprenant.
//
// yaegi ne le signale pas comme le compilateur (« undefined: route ») : sur un type de paramètre, comme
// `func(router route.Router)`, il panique avec un « nil pointer dereference » sans fichier ni ligne. On cherche
// donc ce cas avant de lui passer le code, pour rendre le message de `go build`.
func missingImport() error {
	if err := unavailableImport(); err != nil {
		return err
	}
	byDir := map[string][]string{}
	_ = filepath.WalkDir(Root, func(p string, d fs.DirEntry, err error) error {
		if err != nil {
			return nil
		}
		if d.IsDir() && p != Root && (d.Name() == "storage" || d.Name() == "vendor" || strings.HasPrefix(d.Name(), ".")) {
			return filepath.SkipDir
		}
		if !d.IsDir() && strings.HasSuffix(d.Name(), ".go") {
			byDir[filepath.Dir(p)] = append(byDir[filepath.Dir(p)], p)
		}
		return nil
	})
	dirs := make([]string, 0, len(byDir))
	for dir := range byDir {
		dirs = append(dirs, dir)
	}
	sort.Strings(dirs)
	for _, dir := range dirs {
		if err := missingImportIn(byDir[dir]); err != nil {
			return err
		}
	}
	return nil
}

var majorVersion = regexp.MustCompile(`^v[0-9]+$`)

func missingImportIn(paths []string) error {
	sort.Strings(paths)
	fset := token.NewFileSet()
	var files []*ast.File
	// Les noms déclarés au niveau du paquet, par nom de paquet (un dossier de tests peut en avoir deux : x et x_test).
	declared := map[string]map[string]bool{}
	for _, p := range paths {
		src, err := os.ReadFile(p)
		if err != nil {
			continue
		}
		f, err := parser.ParseFile(fset, p, src, 0)
		if err != nil {
			return nil // une erreur de syntaxe : yaegi la signale lui-même, avec sa ligne
		}
		files = append(files, f)
		names := declared[f.Name.Name]
		if names == nil {
			names = map[string]bool{}
			declared[f.Name.Name] = names
		}
		for _, decl := range f.Decls {
			switch d := decl.(type) {
			case *ast.FuncDecl:
				if d.Recv == nil {
					names[d.Name.Name] = true
				}
			case *ast.GenDecl:
				for _, spec := range d.Specs {
					switch s := spec.(type) {
					case *ast.TypeSpec:
						names[s.Name.Name] = true
					case *ast.ValueSpec:
						for _, n := range s.Names {
							names[n.Name] = true
						}
					}
				}
			}
		}
	}
	for _, f := range files {
		imported := map[string]bool{}
		sure := true
		for _, spec := range f.Imports {
			if spec.Name != nil {
				imported[spec.Name.Name] = true
				sure = sure && spec.Name.Name != "."
				continue
			}
			p, _ := strconv.Unquote(spec.Path.Value)
			base := path.Base(p)
			imported[base] = true
			// gopkg.in/yaml.v3, …/v2, go-xxx : le nom du paquet peut différer du chemin, on ne devine pas.
			sure = sure && token.IsIdentifier(base) && !majorVersion.MatchString(base)
		}
		if !sure {
			continue
		}
		var found *ast.Ident
		ast.Inspect(f, func(n ast.Node) bool {
			if found != nil {
				return false
			}
			sel, ok := n.(*ast.SelectorExpr)
			if !ok {
				return true
			}
			// Obj != nil : une variable, un paramètre ou un type local, que l'analyseur a déjà résolu.
			if id, ok := sel.X.(*ast.Ident); ok && id.Obj == nil && !imported[id.Name] && !declared[f.Name.Name][id.Name] {
				found = id
			}
			return true
		})
		if found != nil {
			pos := fset.Position(found.Pos())
			return Clean(fmt.Errorf("%s:%d:%d: undefined: %s (le paquet %s n'est pas importé : ajoutez son chemin dans import ( … ), en haut du fichier)",
				filepath.ToSlash(pos.Filename), pos.Line, pos.Column, found.Name, found.Name))
		}
	}
	return nil
}
