Ce projet est une application **Goravel**, un framework Go inspiré de Laravel : routes, contrôleurs,
réponses JSON, tests. Il tourne entièrement dans votre navigateur. Le framework est compilé en
WebAssembly, et votre code Go est relu à chaque modification.

La route `GET /salut/{nom}` existe déjà dans `routes/web.go`. Elle appelle `SalutController.Salut`, qui ne
répond pour l'instant qu'un message provisoire.

Complétez le contrôleur :

1. lisez le prénom dans le paramètre de route `nom` ;
2. répondez en JSON `{"message": "Salut Ada !"}` pour `/salut/Ada` ;
3. si le prénom fait moins de deux caractères, répondez **422** avec `{"erreur": "prénom trop court"}`.

Les boutons de requêtes, sous l'aperçu, envoient les deux cas.
