# Bac à sable Symfony

Un projet Symfony 8 tourne dans votre navigateur, sans rien installer : modifiez le code, l'aperçu se met à jour à chaque frappe.

- **Le code** : le contrôleur `src/Controller/AccueilController.php` et le gabarit `templates/accueil.html.twig` sont ouverts. Le bouton ＋ de l'explorateur crée d'autres contrôleurs, services ou gabarits.
- **La configuration** : les fichiers de `config/` (`services.yaml`, `packages/*.yaml`, `routes/*.yaml`, ou leur version PHP) se modifient depuis l'explorateur. Les paquets installés, eux, ne changent pas.
- **L'aperçu** : tapez une adresse dans sa barre, par exemple `/bonjour/Ada`.
- **La console** : l'onglet Console lance `bin/console`, par exemple `debug:router`.
- **La base de données** : Doctrine ORM est installé, sur une base SQLite. `make:entity` crée une entité, `doctrine:schema:update --force` crée ses tables. Dans un lien partagé, la base part vide : un contrôleur peut la recréer avec le `SchemaTool` de Doctrine.
- **Exécuter** recharge l'aperçu ; **Nouveau projet** repart du projet de départ.

**Partager** crée un lien qui contient votre code : la personne qui l'ouvre retrouve votre projet tel quel, sans compte. Le code voyage dans le lien lui-même, après le `#` : le serveur ne le reçoit pas et ne le garde pas. Votre brouillon reste dans ce navigateur.
