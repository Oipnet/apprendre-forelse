# Lire un en-tête avec `#[MapRequestHeader]`

**Nouveau dans Symfony 8.1.** Les attributs `#[MapQueryParameter]`, `#[MapQueryString]` et `#[MapRequestPayload]` donnent déjà au contrôleur ce qu'il attend de la requête. Il manquait les en-têtes : `#[MapRequestHeader]` comble ce manque.

## Avant

```php
public function __invoke(Request $request): Response
{
    $version = $request->headers->get('X-Api-Version');
    if (null === $version) {
        return new Response('En-tête X-Api-Version manquant.', 400);
    }
    // …
}
```

## Depuis Symfony 8.1

```php
public function __invoke(#[MapRequestHeader] string $xApiVersion): Response
```

L'argument est typé `string`, `array` ou `AcceptHeader`. S'il n'est ni nullable ni doté d'une valeur par défaut, l'en-tête devient obligatoire : son absence donne une réponse 400.

## À faire

Réécrivez `VersionController` avec `#[MapRequestHeader]`. Il ne doit plus recevoir d'objet `Request`, et doit répondre comme avant. Les deux requêtes de l'onglet « Requêtes » vous permettent de vérifier.
