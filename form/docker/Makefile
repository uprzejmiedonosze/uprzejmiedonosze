
build:
	@docker build -t webapp .

#run:
	#docker run --detach --volume $(shell pwd)/../export/:/var/www --rm --publish 80:80/tcp --name webapp webapp:latest

runi:
	docker run --interactive --rm --publish 80:80/tcp \
		--volume $(shell pwd)/../export:/var/www/uprzejmiedonosze.net/webapp --name webapp webapp:latest

shell:
	docker exec -it webapp /bin/bash
