# Thèmes : le contrat entre le moteur et un thème

Un thème habille une instance sans toucher au moteur : pas de fork, pas d'image à reconstruire, rien à republier au
titre de l'AGPL. C'est un dossier, déposé dans `THEMES_DIR` et choisi dans `/admin` → Thèmes (voir le README,
« Habiller son instance »).

```
themes/mon-theme/
  theme.yaml        nom, couleurs, polices, images, textes de l'accueil, fichiers à charger
  logo.svg …        les images nommées dans theme.yaml
  templates/        des gabarits Twig qui remplacent ceux du moteur (ce document)
  assets/           feuilles de style, scripts, polices et images, servis tels quels
```

Ce document dit ce sur quoi un thème peut compter d'une version à l'autre. Tout ce qu'il ne cite pas peut changer
dans une version mineure.

## Les points de surcharge

Un fichier de `templates/` remplace le gabarit de même nom du moteur. Les **points de surcharge** sont faits pour ça :
chacun porte en tête un commentaire `{# @theme … #}` qui donne ses variables et ce qui est obligatoire. La liste fait
foi dans `App\Theme\TemplateContract::POINTS`.

| Gabarit | Ce qu'il habille |
| --- | --- |
| `base.html.twig` | la page de base (blocs `title`, `robots`, `font_preloads`, `stylesheets`, `javascripts`, `body_class`, `header`, `brand_chip`, `body`, `footer`). Mieux vaut surcharger `_header` et `_footer` que la remplacer : elle pose le référencement, la CSP, les fichiers du moteur et du thème. |
| `_header.html.twig` | l'en-tête : marque, navigation, menu du compte |
| `_footer.html.twig` | le pied de page (la version et la licence du moteur y restent obligatoires) |
| `home.html.twig` | l'accueil, fait des sections ci-dessous ; le remplacer change leur ordre ou en retire |
| `home/_hero.html.twig`, `home/_demo.html.twig` | le bandeau et son illustration (`home.demo`) |
| `home/_promise.html.twig`, `home/_how.html.twig`, `home/_ai.html.twig`, `home/_audience.html.twig` | les sections de présentation |
| `home/_showcase.html.twig`, `home/_author.html.twig` | le fil rouge et « qui est derrière » (`home.showcase`, `home.author`) |
| `home/_tracks.html.twig`, `home/_track_card.html.twig` | les parcours ; la fiche sert aussi au catalogue `/parcours` |
| `home/_signup.html.twig`, `home/_faq.html.twig` | l'inscription ou la liste d'attente, les questions fréquentes |
| `blog/_article_card.html.twig` | un article dans la liste du blog |
| `bundles/TwigBundle/Exception/error.html.twig` | les pages d'erreur (404, 403, 500…) |

**Versionnage.** Retirer ou renommer une variable d'un point de surcharge, ou le point lui-même, est un changement
**majeur**. En ajouter une est mineur.

Les autres gabarits se remplacent aussi, mais sans garantie : une variable peut y changer dans une version mineure.

## Ce qui ne se remplace pas

La **page d'exercice** et les **éditeurs de l'atelier** (`exercise/play.html.twig`, `studio/edit.html.twig`,
`studio/lesson.html.twig`) gardent l'habillage du moteur : un thème qui en dépose un est ignoré. Le thème les habille
par sa palette `editor:` de `theme.yaml`, sa police `fonts.mono`, son nom et son logo. Ils ne chargent ni les feuilles,
ni les scripts du thème.

## Feuilles, scripts et polices

`theme.yaml` liste les fichiers de `assets/` à charger sur les pages du site, après ceux du moteur :

```yaml
stylesheets: [assets/theme.css]
scripts: [assets/theme.js]
preload: [assets/fonts/titres.woff2]
replaces_engine_styles: false   # true : la feuille du moteur n'est plus chargée
```

- Une feuille trouve ses polices et ses images par des chemins relatifs (`url(fonts/titres.woff2)`).
- Seuls les `.css`, `.js`, `.woff2`, `.svg`, `.png`, `.webp` et `.jpg` de `assets/` sont servis.
- Les scripts **ajoutent** (animations, composants d'accueil) : le JavaScript du moteur reste chargé. Respectez
  `prefers-reduced-motion`.
- Tout est servi par le domaine de l'instance : la CSP n'a rien à ouvrir.

On peut compiler sa feuille avec l'outil de son choix (Tailwind ou non) : le moteur ne sert que le résultat.

## Aperçu, activation

Dans `/admin` → Thèmes : l'état de chaque thème (son `theme.yaml` valable, ses gabarits qui se compilent), un aperçu
qui ne le montre qu'à vous, puis l'activation pour tous. `bin/console app:theme:verifier` vérifie le thème actif,
`bin/console app:theme:activer <thème>` en active un. Rien ne se téléverse depuis l'admin : les gabarits et les scripts
d'un thème s'exécutent avec les droits de la plateforme.

## Et l'AGPL ?

Un thème est de la configuration montée à côté du moteur, pas une modification du moteur : il n'a pas à être publié.
Seul le pied de page doit garder la version et la licence du moteur, comme l'exige la licence.
