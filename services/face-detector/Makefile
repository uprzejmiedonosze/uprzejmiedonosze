
RSYNC       := rsync
RSYNC_FLAGS := --human-readable --recursive --delete --exclude '__pycache__' --exclude '.venv'
HOSTING     := nieradka.net
COMPOSE     := docker compose -f ../compose.yml

# Deploy via Docker (build on server)
.PHONY: deploy
deploy:
	@$(RSYNC) $(RSYNC_FLAGS) \
	    ../face-detector ../face-detect-consumer ../compose.yml \
	    $(HOSTING):/var/www/uprzejmiedonosze.net/services/
	ssh $(HOSTING) "cd /var/www/uprzejmiedonosze.net/services && \
	    docker compose --profile prod --profile staging build \
	        face-detector face-detect-consumer-prod face-detect-consumer-staging && \
	    docker compose --profile prod --profile staging up -d"

.PHONY: build
build:
	$(COMPOSE) --profile staging build face-detector
