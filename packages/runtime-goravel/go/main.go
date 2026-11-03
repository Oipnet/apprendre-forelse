//go:build js

// Le pont entre JavaScript (le worker du playground, ou Node pour content:check) et Goravel.
//
// Il n'y a pas de socket dans un navigateur : l'application n'écoute rien. Le JS appelle le routeur
// directement (route.ServeHTTP), comme le Service Worker de l'aperçu le fait avec php-wasm.
//
// Une instance du module WebAssembly sert un seul état du code de l'apprenant : quand un fichier change,
// le JS en démarre une neuve, à partir du module déjà compilé. Deux modes, choisis par os.Args[1] :
//
//   - serve : démarre l'application, puis appelle host.ready(api) ; api.fetch(...) sert une requête et
//     api.exit() arrête l'instance ;
//   - test : démarre l'application sur une base de test, lance les fonctions TestXxx des dossiers de tests,
//     puis appelle host.done(résultat) et s'arrête.
//
// L'objet host est posé par le JS sur globalThis, sous le nom que donne la variable GORAVEL_HOST : deux
// instances (l'aperçu et les tests) peuvent ainsi vivre dans le même worker.
package main

import (
	"bytes"
	"flag"
	"fmt"
	"html"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"syscall/js"
	"testing"
	"time"

	contractsfoundation "github.com/goravel/framework/contracts/foundation"
	contractsroute "github.com/goravel/framework/contracts/route"
	"github.com/goravel/framework/foundation"

	"goravel/app/facades"
	"goravel/bootstrap"
	"goravel/config"
	"goravel/learner"
)

func main() {
	host := js.Global().Get(os.Getenv("GORAVEL_HOST"))
	if host.IsUndefined() {
		fmt.Fprintln(os.Stderr, "GORAVEL_HOST : objet hôte introuvable sur globalThis")
		os.Exit(2)
	}
	mode := "serve"
	if len(os.Args) > 1 {
		mode = os.Args[1]
	}
	if err := os.Chdir(learner.Root); err != nil {
		host.Call("fail", "projet introuvable : "+err.Error())
		return
	}
	if mode == "test" {
		host.Call("done", runTests())
		return
	}
	serve(host)
}

// boot démarre Goravel, avec les routes de l'apprenant. Une erreur de son code (compilation, panique au
// démarrage) ne fait pas échouer le démarrage : l'aperçu l'affiche à chaque requête.
func boot() (it *learner.Interpreter, learnerErr error, err error) {
	defer func() {
		if p := recover(); p != nil {
			err = fmt.Errorf("démarrage de Goravel : %v", p)
		}
	}()
	it, err = learner.New()
	if err != nil {
		return nil, nil, err
	}
	var app contractsfoundation.Application = foundation.Setup().
		WithMigrations(bootstrap.Migrations).
		WithRouting(func() { learnerErr = it.Routes() }).
		WithProviders(bootstrap.Providers).
		WithConfig(config.Boot).
		Create()
	_ = app
	return it, learnerErr, nil
}

func serve(host js.Value) {
	_, learnerErr, err := boot()
	if err != nil {
		host.Call("fail", err.Error())
		return
	}
	var route contractsroute.Route
	if learnerErr == nil {
		route = facades.Route()
	}
	stop := make(chan struct{})
	api := js.Global().Get("Object").New()
	api.Set("fetch", js.FuncOf(func(_ js.Value, args []js.Value) any {
		method, url, headers, body := args[0].String(), args[1].String(), args[2], bytesFromJS(args[3])
		return promise(func() any {
			if learnerErr != nil {
				return errorResponse(learnerErr)
			}
			return handle(route, method, url, headers, body)
		})
	}))
	api.Set("exit", js.FuncOf(func(js.Value, []js.Value) any {
		close(stop)
		return nil
	}))
	if learnerErr != nil {
		api.Set("error", learnerErr.Error())
	}
	host.Call("ready", api)
	<-stop
}

func handle(route contractsroute.Route, method, url string, headers js.Value, body []byte) (out any) {
	started := time.Now()
	defer func() {
		// Une panique dans un contrôleur : la page d'erreur, comme le ferait le middleware de récupération de Gin.
		if p := recover(); p != nil {
			out = errorResponse(fmt.Errorf("panic: %v", p))
		}
	}()
	req := httptest.NewRequest(method, url, bytes.NewReader(body))
	keys := js.Global().Get("Object").Call("keys", headers)
	for i := 0; i < keys.Length(); i++ {
		k := keys.Index(i).String()
		req.Header.Set(k, headers.Get(k).String())
	}
	rec := httptest.NewRecorder()
	route.ServeHTTP(rec, req)
	return response(rec.Code, rec.Header(), rec.Body.Bytes(), time.Since(started))
}

func errorResponse(err error) any {
	page := "<!doctype html><meta charset=\"utf-8\"><title>Erreur</title>" +
		"<body style=\"font-family:system-ui,sans-serif;padding:1.5rem\"><h1 style=\"color:#b91c1c;font-size:1.25rem\">Le code Go ne démarre pas</h1>" +
		"<pre style=\"white-space:pre-wrap;background:#fef2f2;padding:1rem;border-radius:.5rem\">" + html.EscapeString(learner.Clean(err).Error()) + "</pre>"
	return response(http.StatusInternalServerError, http.Header{"Content-Type": {"text/html; charset=utf-8"}}, []byte(page), 0)
}

func response(status int, header http.Header, body []byte, d time.Duration) any {
	h := js.Global().Get("Object").New()
	for k, values := range header {
		list := js.Global().Get("Array").New()
		for _, v := range values {
			list.Call("push", v)
		}
		h.Set(k, list)
	}
	res := js.Global().Get("Object").New()
	res.Set("status", status)
	res.Set("headers", h)
	res.Set("body", bytesToJS(body))
	res.Set("durationMs", float64(d.Microseconds())/1000)
	return res
}

// runTests : chaque fonction TestXxx des dossiers de tests, lancée par le paquet testing (sortie -v).
// Le résultat donne, par test, son statut et son fichier ; les messages se lisent dans la sortie, que le JS
// capture et découpe par test.
func runTests() any {
	result := js.Global().Get("Object").New()
	cases := js.Global().Get("Array").New()
	result.Set("cases", cases)

	// Une base à part, vidée à chaque run : les tests ne voient pas ce que l'aperçu a écrit.
	base := filepath.Join("storage", "testing.sqlite")
	_ = os.MkdirAll("storage", 0o755)
	_ = os.Remove(base)
	_ = os.Setenv("DB_DATABASE", base)
	_ = os.Setenv("APP_ENV", "testing")

	it, learnerErr, err := boot()
	if err == nil {
		err = learnerErr
	}
	if err != nil {
		result.Set("error", learner.Clean(err).Error())
		return result
	}
	var tests []learner.Test
	for _, dir := range learner.TestDirs() {
		found, err := it.Tests(dir)
		if err != nil {
			result.Set("error", err.Error())
			return result
		}
		tests = append(tests, found...)
	}

	testing.Init()
	_ = flag.CommandLine.Parse([]string{"-test.v=true", "-test.count=1", "-test.paniconexit0=false"})
	status := map[string]string{}
	durations := map[string]time.Duration{}
	internal := make([]testing.InternalTest, 0, len(tests))
	for _, test := range tests {
		test := test
		internal = append(internal, testing.InternalTest{Name: test.Name, F: func(t *testing.T) {
			started := time.Now()
			t.Cleanup(func() {
				durations[test.Name] = time.Since(started)
				switch {
				case t.Skipped():
					status[test.Name] = "skipped"
				case t.Failed():
					status[test.Name] = "failed"
				default:
					status[test.Name] = "passed"
				}
			})
			defer func() {
				if p := recover(); p != nil {
					t.Fatalf("panic: %v", p)
				}
			}()
			test.Func(t)
		}})
	}
	testing.MainStart(testDeps{}, internal, nil, nil, nil).Run()
	for _, test := range tests {
		c := js.Global().Get("Object").New()
		c.Set("name", test.Name)
		c.Set("file", test.File)
		s := status[test.Name]
		if s == "" {
			s = "error"
		}
		c.Set("status", s)
		c.Set("timeMs", float64(durations[test.Name].Microseconds())/1000)
		cases.Call("push", c)
	}
	return result
}

// promise : exécute f dans une goroutine (elle peut attendre le système de fichiers, qui répond par
// callback) et rend une Promise résolue par son résultat.
func promise(f func() any) js.Value {
	var executor js.Func
	executor = js.FuncOf(func(_ js.Value, p []js.Value) any {
		resolve, reject := p[0], p[1]
		go func() {
			defer executor.Release()
			defer func() {
				if r := recover(); r != nil {
					reject.Invoke(js.Global().Get("Error").New(fmt.Sprint(r)))
				}
			}()
			resolve.Invoke(f())
		}()
		return nil
	})
	return js.Global().Get("Promise").New(executor)
}

func bytesFromJS(v js.Value) []byte {
	if v.IsUndefined() || v.IsNull() {
		return nil
	}
	b := make([]byte, v.Get("length").Int())
	js.CopyBytesToGo(b, v)
	return b
}

func bytesToJS(b []byte) js.Value {
	arr := js.Global().Get("Uint8Array").New(len(b))
	js.CopyBytesToJS(arr, b)
	return arr
}

