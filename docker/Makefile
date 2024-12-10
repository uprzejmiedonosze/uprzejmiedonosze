
build:
	@PATH="${PATH}:/usr/local/bin" docker compose build

run:
	docker compose up -d

runi:
	GREP_COLOR='1;30;49' docker compose up | grep --color -E '^|.*vendor.*'

shell:
	docker compose exec webapp bash

stop:
	docker compose down

prune:
	@docker system prune --volumes -f
