# AGENTS.md

This is the base Laravel project in which the exercises of a track run, both in the
browser (PHP compiled to WebAssembly, see playground/) and on the platform
(`content:check`, native PHP). Check `composer.json` for the exact Laravel/PHP version.

## What is special here

- The project never leaves a sandbox: `.env` is committed on purpose (its `APP_KEY`
  protects nothing), the database is SQLite (`database/database.sqlite`, recreated in
  memory at every load), sessions and cache use files under `storage/`.
- Exercises overlay their `starter/`, `tests/` and `solution/` files on top of this
  project. Keep it minimal: no auth scaffolding, no Vite, no JavaScript build.
- PHPUnit runs every test under `tests/` (see `phpunit.xml`); `APP_ENV=testing` is forced.
- No `proc_open`, no network, no real queue worker in WebAssembly: `QUEUE_CONNECTION=sync`.

## Conventions

Idiomatic Laravel, as in https://laravel.com/docs: routes in `routes/web.php`,
controllers in `app/Http/Controllers`, Eloquent models in `app/Models`, Blade views in
`resources/views`, migrations in `database/migrations`, feature tests extending
`Tests\TestCase` with `RefreshDatabase`.
