# Read a header with `#[MapRequestHeader]`

**New in Symfony 8.1.** The `#[MapQueryParameter]`, `#[MapQueryString]` and `#[MapRequestPayload]` attributes already hand the controller what it expects from the request. Headers were missing: `#[MapRequestHeader]` fills that gap.

## Before

```php
public function __invoke(Request $request): Response
{
    $version = $request->headers->get('X-Api-Version');
    if (null === $version) {
        return new Response('Missing X-Api-Version header.', 400);
    }
    // …
}
```

## Since Symfony 8.1

```php
public function __invoke(#[MapRequestHeader] string $xApiVersion): Response
```

The argument is typed `string`, `array` or `AcceptHeader`. If it is neither nullable nor given a default value, the header becomes required: a missing one gives a 400 response.

## Your task

Rewrite `VersionController` with `#[MapRequestHeader]`. It must no longer receive a `Request` object, and must respond as before. The two requests in the “Requests” tab let you check.
