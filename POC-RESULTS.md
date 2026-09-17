# Bilan du POC — Symfony dans le navigateur

**Décision : GO.** Symfony 8.1 tourne entièrement dans le navigateur (PHP 8.4 compilé en WebAssembly via `@php-wasm/web`), sans serveur d'exécution. La boucle d'exercice complète fonctionne : éditer dans Monaco avec complétion → aperçu en direct → tests PHPUnit → objectifs validés.

## Mesures (Chrome, macOS, 2026-09-11)

| Étape | À froid | À chaud (cache HTTP) | Objectif |
|---|---|---|---|
| Runtime PHP prêt (téléchargement + décompression + boot) | ~0,7 s en local | 0,34 s | < 2 s ✅ |
| Interface utilisable | — | 0,5 s | — |
| 1re page Symfony (compilation du container dev) | ~0,45 s | ~0,45 s | — |
| Requêtes suivantes / rechargement après édition | 40–80 ms (300 ms si le container se recompile) | | < 1 s ✅ |
| Suite de 5 tests `WebTestCase` (1er run / suivants) | 0,4–0,75 s / ~0,1 s | | < 5 s ✅ |

Volumes téléchargés : binaire PHP 19 Mo (**7,6 Mo gzip**), environnement Symfony **4,9 Mo** (zip), index de complétion 672 Ko (~80 Ko gzip). En production, prévoir un CDN avec Brotli et un cache long terme : c'est un coût payé une seule fois par apprenant.

## Ce qui est validé

- **Symfony 8.1 + Twig + PHPUnit 13 + WebTestCase** en WASM, sans modifier le code Symfony. Extensions disponibles : ctype, iconv, mbstring, dom, tokenizer, pdo_sqlite… (`intl` est chargeable en option).
- **Aperçu** : un Service Worker (`poc/public/preview-sw.js`) intercepte `/preview/*` et fait exécuter la requête par PHP. Base URL correcte (`SCRIPT_NAME=/preview/index.php`) : liens générés, formulaires POST, fichiers statiques de `public/`, page d'exception Symfony et cookies (stockés côté worker).
- **Modifications à chaud** : les routes en attribut et les templates Twig modifiés sont détectés, sans intervention.
- **Tests** : le runner `poc/src/runtime/run-tests.php` renvoie un rapport JSON construit depuis le log JUnit, puis chaque test est associé à un objectif de l'exercice.
- **Complétion sans serveur de langage** : l'index est généré par Reflection (`tools/build-completion.php`). Couvre `$this->` (méthodes d'`AbstractController`), `$var->` (type déduit, y compris `createClient()->request()` → `Crawler`), `Classe::`, `#[` (attributs), `new`, `use`, avec **ajout automatique du `use`**. Côté Twig : `path('` propose les routes du projet, et `{{` les variables passées par le contrôleur.

## Décisions techniques issues du POC

1. **Deux instances PHP** (aperçu en `dev`, tests en `test`) dans le même worker. Enchaîner requêtes HTTP et runs PHPUnit sur une seule instance la bloque, et l'isolation évite de toute façon que les tests polluent l'aperçu.
2. **Le `.wasm` est compilé une seule fois** (`WebAssembly.compileStreaming`), puis instancié pour chaque instance via le hook Emscripten `instantiateWasm`. L'instance de tests est préparée en arrière-plan : une fois la page chargée, plus rien ne dépend du réseau.
3. **Écriture différée par fichier** (debounce 400 ms), vidée avant chaque run de tests.
4. **Seul PHP 8.4 est embarqué** : les autres versions sont remplacées par un paquet vide via `overrides` npm (`poc/stubs/php-wasm-unused`), ce qui fait passer `node_modules` de 1,1 Go à ~430 Mo.
5. **Contenu générique** : `content/tracks/<parcours>/exercises/<exercice>/`, avec des environnements réutilisables (`environments/symfony-8`), derrière une interface `Runtime` qui permettra d'ajouter un `ContainerRuntime` pour de futurs parcours.

## Limites et risques

- ⚠️ **Licence** : les paquets `@php-wasm/*` sont sous **GPL-2.0-or-later**. Pour un produit commercial distribué au navigateur, **faire valider juridiquement**, ou évaluer [seanmorris/php-wasm](https://github.com/seanmorris/php-wasm) (licence Apache-2.0 d'après le dépôt, à vérifier).
- **Un seul onglet** : le Service Worker transmet les requêtes à la fenêtre hôte focalisée. Pour gérer plusieurs onglets, il faudra identifier la page hôte (ex. identifiant dans le chemin d'aperçu).
- **Hors de portée du WASM** : MySQL/PostgreSQL réels, Redis, Messenger avec un vrai transport, CLI longue durée, `proc_open`. Doctrine devra passer par SQLite (à valider). Les parcours avancés justifieront un runtime conteneur.
- **Navigateurs** : testé sur Chrome uniquement. À valider sur Firefox et Safari (Service Worker, JSPI ou repli Asyncify).
- **Mémoire** : deux instances × (~26 Mo de fichiers + tas WASM). À surveiller sur mobile ou avec des environnements plus lourds.
- **Mise en page** : prévue pour un écran de bureau (≥ 1100 px), rien de prévu pour mobile.

## Prochaines étapes suggérées

1. Valider Doctrine + SQLite (entité `Plat`, fixtures) : c'est le prochain risque technique.
2. Tester sur Firefox et Safari, puis mesurer à froid derrière un vrai CDN.
3. Trancher la question de la licence avant d'investir dans la plateforme.
4. Rédiger le document de vision : parcours, arbre de compétences, boss fights « incident de prod », IA (tuteur socratique, revue de code).

## Lancer le POC

```bash
tools/build-env.sh symfony-8   # environnement Symfony → poc/public/envs/
cd poc && npm install && npm run dev
```

En développement, le bouton **Solution** charge la solution de l'exercice.
