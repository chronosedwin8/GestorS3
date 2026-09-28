# Atajos para el entorno Docker. En XAMPP/servidor sin Docker usa los comandos equivalentes
# (php bin/console ..., vendor/bin/phinx ..., npm run build) descritos en el README.
DC      = docker compose
PHP     = $(DC) exec php php
COMPOSE = $(DC) exec php composer

.PHONY: up down restart install migrate seed setup-s3 test stan cs cs-fix css css-watch cleanup purge logs shell

up: ## Levanta el entorno completo, instala dependencias y migra
	@test -f .env || cp .env.docker .env
	$(DC) up -d --build
	$(COMPOSE) install --no-interaction
	$(MAKE) migrate
	$(MAKE) setup-s3
	@echo "App: http://localhost:8081 · S3 local: http://localhost:8333 · Mailpit: http://localhost:8025"

down:
	$(DC) down

restart:
	$(DC) restart php nginx

install:
	$(COMPOSE) install --no-interaction
	npm install
	npm run build

migrate: ## Ejecuta las migraciones de Phinx
	$(PHP) vendor/bin/phinx migrate -c phinx.php

seed: ## Crea el administrador inicial (pide correo, nombre y contraseña)
	$(DC) exec php php bin/console user:create-admin

setup-s3: ## Configura el bucket (privado, CORS, ciclo de vida) y verifica permisos
	$(PHP) bin/console s3:setup

test: ## Pruebas PHPUnit (usa la base de datos fileshare_test)
	$(PHP) vendor/bin/phpunit

stan: ## Análisis estático nivel 6
	$(PHP) -d memory_limit=1G vendor/bin/phpstan analyse

cs: ## Verifica PSR-12
	$(PHP) vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix:
	$(PHP) vendor/bin/php-cs-fixer fix

css: ## Compila Tailwind y copia los ES modules a public/assets
	npm run build

css-watch:
	npm run css:watch

cleanup: ## Aborta subidas abandonadas (programar cada hora)
	$(PHP) bin/console uploads:cleanup

purge: ## Borra de S3 archivos eliminados hace más de 30 días
	$(PHP) bin/console storage:purge --days=30

logs:
	$(DC) logs -f php nginx

shell:
	$(DC) exec php sh
