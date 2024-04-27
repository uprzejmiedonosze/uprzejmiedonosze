
build:
	@PATH="${PATH}:/usr/local/bin" docker compose build

run:
	docker compose up -d

runi:
	docker compose up

shell:
	docker compose exec webapp bash

stop:
	docker compose stop

prune:
	@docker system prune --volumes -f
