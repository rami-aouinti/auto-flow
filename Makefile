.PHONY: build up down logs shell install quality cs cs-fix phpcs phpstan phpmd rector rector-fix test coverage audit api-docs
build:
	docker compose build --no-cache
up:
	docker compose up -d --build
down:
	docker compose down
logs:
	docker compose logs -f --tail=100
shell:
	docker compose exec php bash
install:
	docker compose exec php composer install
quality:
	docker compose exec php composer quality
cs:
	docker compose exec php composer cs
cs-fix:
	docker compose exec php composer cs:fix
phpcs:
	docker compose exec php composer phpcs
phpstan:
	docker compose exec php composer phpstan
phpmd:
	docker compose exec php composer phpmd
rector:
	docker compose exec php composer rector:check
rector-fix:
	docker compose exec php composer rector:fix
test:
	docker compose exec php composer test
coverage:
	docker compose exec php composer coverage
audit:
	docker compose exec php composer security:audit

api-docs:
	docker compose exec offer-service php bin/console nelmio:apidoc:dump --format=json
	docker compose exec pricing-service php bin/console nelmio:apidoc:dump --format=json
	docker compose exec vehicle-catalog-service php bin/console nelmio:apidoc:dump --format=json
	@echo "Order Management OpenAPI contract: apps/order-service/docs/openapi.yaml"
