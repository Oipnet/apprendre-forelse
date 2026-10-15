## What you learned

- A **route** maps a URL to a controller method.
- A **controller** is an ordinary PHP class whose methods each return a `Response`.
- A **route parameter** (`{name}`) arrives as is as an argument of the method.

## Route and controller

Symfony needs no configuration file to declare a page: the `#[Route]` attribute on a method is enough. The name (`name:`) is used to generate the URL elsewhere in the application, without copying it.

```php
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class BonjourController extends AbstractController
{
    #[Route('/hello', name: 'app_hello')]
    public function index(): Response
    {
        return new Response('Hello Symfony!');
    }
}
```

`AbstractController` is not mandatory, but it brings shortcuts (`render()`, `json()`, `redirectToRoute()`…) that you will use from the next chapter on.

## Route parameter

A part between braces in the path becomes a parameter. Symfony passes it to the argument with the same name, converted according to the declared type.

```php
#[Route('/hello/{name}', name: 'app_hello_name')]
public function name(string $name): Response
{
    return new Response(sprintf('Hello %s!', $name));
}
```

## Pitfalls

- Two routes with the same path: the first one declared wins, without any warning.
- `/hello` and `/hello/{name}` are two separate routes: the second does not answer `/hello` without a default value (`{name?}`).
- A controller method that does not return a `Response` causes an error: `echo` displays nothing useful.

## Going further

- [Create your first page: route and controller](https://symfony.com/doc/current/page_creation.html)
- [Route parameters](https://symfony.com/doc/current/routing.html#route-parameters)
- [Controllers](https://symfony.com/doc/current/controller.html)
