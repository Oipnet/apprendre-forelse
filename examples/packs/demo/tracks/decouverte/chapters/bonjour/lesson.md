## Ce que vous avez appris

- Une **route** associe une URL à une méthode de contrôleur.
- Un **contrôleur** est une classe PHP ordinaire dont chaque méthode renvoie une `Response`.
- Un **paramètre de route** (`{prenom}`) arrive tel quel comme argument de la méthode.

## Route et contrôleur

Symfony ne demande aucun fichier de configuration pour déclarer une page : l'attribut `#[Route]` posé sur une méthode suffit. Le nom (`name:`) sert à générer l'URL ailleurs dans l'application, sans la recopier.

```php
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class BonjourController extends AbstractController
{
    #[Route('/bonjour', name: 'app_bonjour')]
    public function index(): Response
    {
        return new Response('Bonjour Symfony !');
    }
}
```

`AbstractController` n'est pas obligatoire, mais il apporte des raccourcis (`render()`, `json()`, `redirectToRoute()`…) que vous utiliserez dès le prochain chapitre.

## Paramètre de route

Une partie entre accolades dans le chemin devient un paramètre. Symfony le transmet à l'argument de même nom, converti selon le type déclaré.

```php
#[Route('/bonjour/{prenom}', name: 'app_bonjour_prenom')]
public function prenom(string $prenom): Response
{
    return new Response(sprintf('Bonjour %s !', $prenom));
}
```

## Les pièges

- Deux routes avec le même chemin : la première déclarée gagne, sans avertissement.
- `/bonjour` et `/bonjour/{prenom}` sont deux routes distinctes : la seconde ne répond pas à `/bonjour` sans valeur par défaut (`{prenom?}`).
- Une méthode de contrôleur qui ne renvoie pas de `Response` provoque une erreur : `echo` n'affiche rien d'utile.

## Pour aller plus loin

- [Créer une page : route et contrôleur](https://symfony.com/doc/current/page_creation.html)
- [Les paramètres de route](https://symfony.com/doc/current/routing.html#route-parameters)
- [Les contrôleurs](https://symfony.com/doc/current/controller.html)
