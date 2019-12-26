
build:
	@docker build -t webapp .

run:
	docker run --detach --rm --publish 80:80/tcp \
		--volume $(shell pwd)/../export:/var/www/uprzejmiedonosze.localhost/webapp --name webapp webapp:latest

runi:
	@docker run --interactive --rm -it \
		--publish 80:80/tcp \
		--volume $(shell pwd)/../export:/var/www/uprzejmiedonosze.localhost/webapp \
		--name webapp webapp

shell:
	@docker exec -it webapp /bin/bash

prune:
	@docker system prune --volumes -f
