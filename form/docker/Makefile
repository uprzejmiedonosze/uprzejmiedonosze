
build:
	@docker build -t webapp .

runi:
	docker run --interactive --volume $(shell pwd)/../DEV:/var/www --rm --publish 8080:80/tcp --name webapp webapp:latest

shell:
	docker exec -it webapp /bin/bash
