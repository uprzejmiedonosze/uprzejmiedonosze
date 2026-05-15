
COMPOSE := docker compose -f ../compose.yml --profile dev

build:
	@PATH="${PATH}:/usr/local/bin" $(COMPOSE) build

run:
	$(COMPOSE) up -d

runi:
	@$(COMPOSE) up | GREP_COLOR='1;30;49' grep --color -E '^|.*vendor.*'

shell:
	docker compose -f ../compose.yml exec webapp bash

stop:
	$(COMPOSE) down

prune:
	@docker system prune --volumes -f
