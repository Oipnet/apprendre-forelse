# Symfony playground

A Symfony 8 project runs in your browser, with nothing to install: edit the code and the preview updates as you type.

- **The code**: the controller `src/Controller/AccueilController.php` and the template `templates/accueil.html.twig` are open. The file tree's ＋ button creates more controllers, services or templates.
- **The configuration**: the files in `config/` (`services.yaml`, `packages/*.yaml`, `routes/*.yaml`, or their PHP version) can be edited from the file tree. The installed packages can't change.
- **The preview**: type an address in its bar, for example `/bonjour/Ada`.
- **The console**: the Console tab runs `bin/console`, for example `debug:router`.
- **The database**: Doctrine ORM is installed, on an SQLite database. `make:entity` creates an entity, `doctrine:schema:update --force` creates its tables. In a shared link, the database starts empty: a controller can recreate it with Doctrine's `SchemaTool`.
- **Run** reloads the preview; **New project** starts again from the starting project.

**Share** creates a link that contains your code: whoever opens it gets your project as it is, without an account. The code travels inside the link itself, after the `#`: the server never receives it nor keeps it. Your draft stays in this browser.
