# Bac à sable PHP

Un projet PHP 8.4 sans framework tourne dans votre navigateur, sans rien installer : modifiez le code, l'aperçu se met à jour à chaque frappe.

- **Le code** : `public/index.php` reçoit chaque requête et choisit la page, `templates/accueil.php` l'affiche. Les classes de `src/` (espace de noms `App\`) sont chargées par Composer, sans `require`. Le bouton ＋ de l'explorateur crée d'autres classes, vues ou scripts.
- **L'aperçu** : tapez une adresse dans sa barre, par exemple `/bonjour/Ada`.
- **La console** : l'onglet Console lance un script, comme un terminal, par exemple `php bin/bonjour.php Ada`.
- **Exécuter** recharge l'aperçu ; **Nouveau projet** repart du projet de départ.

**Partager** crée un lien qui contient votre code : la personne qui l'ouvre retrouve votre projet tel quel, sans compte. Le code voyage dans le lien lui-même, après le `#` : le serveur ne le reçoit pas et ne le garde pas. Votre brouillon reste dans ce navigateur.
