
build:
	@PATH="${PATH}:/usr/local/bin" docker build -t webapp .

run:
	docker run --detach --rm --publish 80-81:80-81/tcp \
		--volume $(shell pwd)/../export:/var/www/uprzejmiedonosze.localhost/webapp \
		--name webapp webapp:latest

runi:
	@docker run --interactive --rm -it \
		--publish 80-81:80-81/tcp \
		--volume $(shell pwd)/../export:/var/www/uprzejmiedonosze.localhost/webapp \
		--name webapp webapp

shell:
	@docker exec -it webapp /bin/bash

prune:
	@docker system prune --volumes -f
