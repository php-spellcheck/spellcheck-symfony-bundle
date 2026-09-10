DOCKER ?= docker compose run --rm php
PHP    ?= php

.DEFAULT_GOAL := help
.PHONY: help build install test stan cs cs-fix smoke shell validate merge release clean

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}'

build: ## Build the development image
	docker compose build

install: ## Install the dependencies
	$(DOCKER) composer install --no-interaction

test: ## Unit and functional tests (no external binary needed)
	$(DOCKER) vendor/bin/phpunit

stan: ## Static analysis
	$(DOCKER) vendor/bin/phpstan analyse --memory-limit=-1

cs: ## Check the coding standard
	$(DOCKER) vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix: ## Fix the coding standard
	$(DOCKER) vendor/bin/php-cs-fixer fix

validate: ## Validate composer.json
	$(DOCKER) composer validate --strict

shell: ## Open a shell in the container
	$(DOCKER) bash

clean: ## Remove caches and generated files
	rm -rf vendor .phpunit.cache .php-cs-fixer.cache build
	rm -rf tests/Fixtures/var

pre-commit: stan cs-fix test