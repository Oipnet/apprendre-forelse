# Raccourcis de développement. `make install` une fois, puis `make dev`.
.PHONY: install dev dev-platform dev-sandbox dev-vite dev-mail test conformite-nuxt check ci build docker

install: ## Dépendances, environnements d'exécution et bases de données (PostgreSQL : voir README)
	cd platform && composer install
	cd playground && npm install
	cd tools/nuxt-sim && npm install
	for env in environments/*/; do tools/build-env.sh "$$(basename "$$env")"; done
	cd platform && php bin/console doctrine:database:create --if-not-exists
	cd platform && php bin/console doctrine:migrations:migrate --no-interaction
	cd platform && php bin/console doctrine:database:create --if-not-exists --env=test

dev: ## Plateforme (localhost:8000), bac à sable (127.0.0.1:8001), Vite (5173) et Mailpit (8025)
	$(MAKE) -j4 dev-platform dev-sandbox dev-vite dev-mail

# Xdebug désactivé : il fait planter le serveur de développement (segfault) et ralentit tout.
dev-platform:
	php -d xdebug.mode=off -S localhost:8000 -t platform/public

dev-sandbox:
	php -d xdebug.mode=off -S 127.0.0.1:8001 -t platform/public

dev-vite:
	cd playground && npm run dev

# Boîte de réception de développement : SMTP sur 1025, lecture sur http://localhost:8025 (brew install mailpit).
# Avec MAILER_DSN=smtp://localhost:1025 dans platform/.env.local, aucun email ne part pour de vrai.
dev-mail:
	mailpit --smtp 127.0.0.1:1025 --listen 127.0.0.1:8025

test: ## Tests du moteur
	cd platform && php -d xdebug.mode=off bin/phpunit
	cd tools/docker-sim && composer install --quiet && php -d xdebug.mode=off vendor/bin/phpunit
	cd playground && npm run typecheck
	cd tools/nuxt-sim && npm run typecheck && npm test

conformite-nuxt: ## Compare le simulateur Nuxt au vrai Nuxt (installe Nuxt dans conformite/reference)
	cd tools/nuxt-sim/conformite/reference && npm install
	cd tools/nuxt-sim && npm run conformite

check: ## Vérifie les packs de contenu (exécute leur PHP : packs de confiance uniquement)
	cd platform && php bin/console content:check

ci: ## Tout ce que vérifie l'intégration continue (attention : le build bascule la plateforme sur les fichiers compilés)
	$(MAKE) test
	$(MAKE) conformite-nuxt
	cd playground && npm run build
	cd platform && php bin/console content:check demo

build: ## Build de production du playground (platform/public/build)
	cd playground && npm run build

docker: ## Image de production + démarrage (http://localhost:8080)
	docker compose up --build
