# Le contrat d'un runtime

Un **runtime** est une façon d'exécuter un projet dans le navigateur de l'apprenant : PHP compilé en
WebAssembly, un simulateur Nuxt, et demain autre chose. Ce paquet ne contient que des **types** — ce que
le moteur attend d'un runtime, et ce qu'il lui fournit. Aucun code, aucune dépendance.

Il existe pour que ni le moteur ni un runtime n'aient à connaître l'autre : le playground importe ce
contrat pour orchestrer, un paquet de runtime l'importe pour s'y conformer.

## Ce qu'un paquet de runtime déclare

Dans son `package.json` :

```json
{
  "forelse": { "runtime": "./src/browser/forelse.ts" }
}
```

Ce module exporte par défaut un `RuntimeManifest` : un identifiant, un libellé, la fabrique du runtime,
et — s'il en a besoin — ce qu'il demande au build (greffons Vite, drapeaux de compilation).

Le playground découvre les paquets qui portent ce champ parmi ses dépendances et les enregistre seuls.
**Ajouter un runtime, c'est installer un paquet** : aucun fichier du moteur n'est touché. Le navigateur
restant un bundle, il faut reconstruire le playground — mais rien à y modifier.

## Les tests côté serveur

`content:check` rejoue les tests d'un exercice sur le serveur, avec le même runner que le navigateur —
sinon les deux verdicts divergeraient. Un framework dont les tests ne sont pas du PHPUnit déclare son
lanceur dans son profil, comme un **spécificateur de paquet** :

```php
testModule: '@forelse/simulateur-nuxt/tests',
```

Le moteur le fait résoudre par Node depuis `playground/` : il ne sait ni où ce paquet est installé, ni
qu'il existe. Le module reçoit le dossier du projet puis les fichiers de test, et écrit sur la sortie
standard `{cases: [{name, status, file, message}], output}`.

## Ce qu'il ne couvre pas

- La moitié serveur (profil du framework) passe par un paquet Composer et l'étiquette de service
  `app.framework` : c'est un autre contrat, déjà en place.
- Un runtime qui aurait besoin d'autre chose que d'un worker — une origine à part, un service distant —
  demanderait d'élargir le contrat. Il bougera au premier paquet tiers.
