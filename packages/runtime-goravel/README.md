# Runtime Goravel (`goravel-wasm`)

Goravel, le framework Go inspiré de Laravel, dans le navigateur. Le playground découvre ce paquet parmi ses
dépendances (`forelse.runtime` dans package.json) ; le moteur le désigne par le profil `goravel`
(`platform/src/Content/Framework/Profiles/GoravelProfile.php`) et l'environnement `environments/goravel-1`.

## Comment ça tourne

- **Le framework est compilé** en WebAssembly (`GOOS=js GOARCH=wasm`) : `go/` est le squelette Goravel 1.18
  allégé (sans gRPC, IA, Postgres, télémétrie, mail), avec SQLite (ncruces/go-sqlite3, SQLite en wasm exécuté
  par wazero).
- **Le code de l'apprenant est interprété** par [yaegi](https://github.com/traefik/yaegi) à chaque démarrage
  d'une instance (`go/learner`) : routes, contrôleurs, modèles, tests. Une modification ne demande donc pas de
  recompiler : la requête suivante démarre une instance neuve du module déjà compilé (≈ 0,6 s).
- **Pas de socket** : le JS appelle le routeur directement (`route.ServeHTTP`), comme le Service Worker de
  l'aperçu le fait avec php-wasm (`go/main.go`).
- **Les fichiers** vivent dans un système de fichiers en mémoire à l'API de Node (`src/memfs.ts`), le même
  dans le worker et sous Node.
- **Les tests** sont de vraies fonctions `TestXxx(t *testing.T)`, lancées par le paquet `testing` de Go.
  `tests.Http(t)` (paquet compilé `goravel/tests`) remplace `s.Http(s.T())` des suites testify du squelette,
  que l'interpréteur ne sait pas parcourir. content:check lance les mêmes tests avec le même binaire, sous
  Node (`bin/tests.ts`).

## Construire le binaire

Il faut Go (version de `go/go.mod`).

```bash
npm run build:wasm        # go/build.sh → dist/goravel.wasm (≈ 109 Mo, ≈ 20 Mo en gzip)
npm test                  # sous Node, avec le binaire (ignorés sans lui)
```

Sans binaire, le playground se construit quand même : le runtime le dit à l'apprenant au démarrage.

## Limites connues

- Les types déclarés par l'apprenant n'ont pas de nom pour la réflexion : gorm ne déduit pas le nom de table
  d'une struct, et une méthode `TableName()` est ignorée. Nommer la table : `Query().Table("livres")`.
- Une interface du framework implémentée par l'apprenant (FormRequest, middleware en struct, migration)
  demande un wrapper yaegi : ajouter le paquet dans `go/gen-symbols.sh` puis relancer le script.
- Les génériques ne sont que partiellement interprétés ; pas d'artisan ni de console dans le navigateur.
- yaegi ne signale pas les variables déclarées et inutilisées (le compilateur Go, si).

## Monter de version

`go get github.com/goravel/framework@vX && go mod tidy`, puis revoir `go/overlay/` (deux fichiers y remplacent
ceux du framework, `process_unix.go` et `support/env/env.go`), relancer `go/gen-symbols.sh` et `npm test`.

## Démo sans le moteur

```bash
environments/bin/build-env.sh goravel-1 packages/runtime-goravel/demo/public
cd packages/runtime-goravel && node ../../playground/node_modules/vite/bin/vite.js --config demo/vite.config.ts
```
