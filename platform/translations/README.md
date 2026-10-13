# Textes de l'interface

Aucun texte n'est écrit en dur dans les gabarits du moteur : ils passent tous par le traducteur de Symfony, et
vivent ici. Le site n'est servi qu'en français (`enabled_locales` dans `config/packages/translation.yaml`) ; une
autre langue n'aura qu'à fournir ses fichiers (`accueil+intl-icu.en.yaml`…). `tests/Architecture/HardcodedTextTest.php`
fait échouer la CI sur un texte resté en dur.

## Fichiers

Un domaine par zone du site, au format [ICU](https://symfony.com/doc/current/reference/formats/message_format.html)
(`+intl-icu` dans le nom) :

| Domaine | Gabarits |
|---|---|
| `messages` | communs : `base`, `_head`, `_header`, `_footer`, pages d'erreur, formulaires, partage (boutons, et dans `src/Share` le texte des images de partage et des liens) ; unités du filtre `duree` (`format.`) |
| `accueil` | `home/` |
| `compte` | `account/`, `security/`, `contact/`, `purchase/` |
| `apprentissage` | `exercise/`, `practice/`, `track/`, `chapter/`, `concept/` ; les refus des API du playground (`api.`) et les textes que le serveur lui envoie (`playground.` : lien de retour, note de démarrage et étapes des profils de framework) |
| `blog` | `blog/` |
| `classement` | `leaderboard/` |
| `certificat` | `certificate/` |
| `emails` | `emails/` |
| `seo` | balises calculées en PHP (`src/Seo`) : title, description, noms lisibles des données structurées, fil d'Ariane, pages fixes de `#[Seo(…, domain: 'seo')]` |
| `validators` | messages des contraintes (`#[Assert\…(message: 'cle')]`), **sans** ICU : le validateur passe ses paramètres sous la forme `{{ limit }}` |

Hors du périmètre, et donc en français dans le gabarit : l'administration, l'atelier des auteurs et l'espace des
chefs de cohorte (outils internes ou clientèle française), et les pages juridiques, qui auront un gabarit par
langue (`privacy.en.html.twig`), relu tel quel.

## Écrire un gabarit

```twig
{% trans_default_domain 'compte' %}

<h1>{{ 'index.titre'|trans }}</h1>
<p>{{ 'index.xp'|trans({xp: user.xp}) }}</p>
<a title="{{ 'index.modifier'|trans }}">…</a>
```

- Clés : chemin du gabarit dans le domaine, puis rôle du texte (`index.titre`, `login.mot_de_passe_oublie`), en
  minuscules et `snake_case`. Un texte partagé par plusieurs gabarits d'une zone va sous `commun.`.
- Paramètres ICU entre accolades : `{xp}`. Pluriels :
  `{n, plural, =0 {aucun exercice} one {# exercice} other {# exercices}}`.
- Un lien ou une mise en valeur au milieu d'une phrase : le HTML reste dans le message, rendu avec `|raw`, et tout
  paramètre qui vient d'un utilisateur est échappé avant (`{nom: user.displayName|e}`). Sans HTML, jamais de `|raw`.
- Une phrase reste entière dans un seul message : on ne colle pas des morceaux traduits, l'ordre des mots change
  d'une langue à l'autre.
- Les dates passent par `format_date('short')` (`13/10/2026` en français) et `format_time('short')`, dans la langue
  de la page.
- Les filtres de format du moteur suivent eux aussi la langue de la page (celle du traducteur) : `date_longue`
  (« 6 octobre 2026 »), `euros` (centimes → « 49,00 € »), `duree` (minutes → « 3 h 15 », unités dans `messages`) et
  `nombre` (« 12 500 », l'espace ordinaire séparant les milliers en français). Plutôt que `number_format`, un nombre
  passe en paramètre déjà écrit : `{xp: user.xp|nombre}`. De même, og:locale et `inLanguage` (schema.org) viennent
  de la langue de la requête (`PageSeo::getOgLocale()`, `getLanguage()`, `getLanguageTag()`).
- Contrôleurs : `$this->addFlash('success', t('flash.enregistre', [], 'compte'))` (fonction
  `Symfony\Component\Translation\t`), `base.html.twig` les rend avec `|trans`.

Les thèmes (dépôt de contenu) peuvent surcharger un gabarit : le leur garde son propre texte, ou utilise les mêmes clés.
