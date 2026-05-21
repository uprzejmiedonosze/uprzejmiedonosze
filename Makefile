# tools
RSYNC         := rsync
RSYNC_FLAGS   := --human-readable --recursive --delete --exclude 'vendor/bin/*'
HOSTING       := nieradka.net
STAGING_HOST  := workflow.nieradka.net
STAGING_PATH  := /opt/staging.uprzejmiedonosze.net
RSYNC_STAGING := --human-readable --recursive --delete \
    --exclude-from=.dockerignore
CYPRESS     := ./node_modules/.bin/cypress
CYPRESS_KEY := 8a0db00f-b36c-4530-9c82-422b0be32b5b
GIT_BRANCH := $(shell git rev-parse --abbrev-ref HEAD)
DATE       := $(shell date '+%Y-%m-%d')
TAG_NAME   := $(shell echo $(GIT_BRANCH)_$(DATE))

.DEFAULT_GOAL := help

# ── Local cleanup ─────────────────────────────────────────────────────────────

.PHONY: clean
clean: ## Remove local build artifacts
	@echo "==> Cleaning"
	@rm -rf export
	@rm -f src/config.env.php
	@rm -rf .parcel-cache/

# ── Database init ─────────────────────────────────────────────────────────────

.PHONY: init-db-dev
init-db-dev: ## Initialize dev SQLite database (run once)
	@docker exec webapp sqlite3 /var/www/uprzejmiedonosze.net/db/store.sqlite \
		-init /var/www/uprzejmiedonosze.net/webapp/sql/init_empty.sql

.PHONY: init-db-staging
init-db-staging: ## Initialize staging SQLite database (run once on server)
	@ssh $(STAGING_HOST) "sqlite3 $(STAGING_PATH)/db/store.sqlite \
		< $(STAGING_PATH)/webapp/sql/init_empty.sql"

# Linting and unit tests run in the Docker builder stage (services/webapp/Dockerfile).
# Use these targets to run them on demand against a running container.

# ── Testing ───────────────────────────────────────────────────────────────────

.PHONY: test
test: ## Run phpunit tests in the webapp container
	@docker exec webapp ./vendor/phpunit/phpunit/phpunit --display-deprecations tests

.PHONY: cypress-local
cypress-local: ## Run Cypress tests against local dev environment
	@CYPRESS_BASE_URL=http://127.0.0.1 $(CYPRESS) run --e2e --env DOCKER=1

# ── Git / release helpers ─────────────────────────────────────────────────────

.PHONY: check-git-clean
check-git-clean: ## Check for uncommitted changes
	@echo "==> Checking if the repo is clean"
	@test "$(shell LC_ALL=en_US git status | grep 'nothing to commit' | wc -l)" -eq 1 \
		|| ( echo "There are uncommitted changes." && exit 1 )

.PHONY: check-branch-main
check-branch-main: ## Check that current branch is main
	@echo "==> Checking if current branch is main"
	@test "$(shell LC_ALL=en_US git status | grep 'origin/main' | wc -l)" -eq 1 \
		|| ( echo "Not on branch main." && exit 1 )

.PHONY: log-from-last-prod
log-from-last-prod: ## Commits since last prod release
	@git log --color --pretty=format:"%cn %ci %s" HEAD...$(call last-tag)

.PHONY: diff-from-last-prod
diff-from-last-prod: ## Diff since last prod release
	@git diff --histogram --color-words $(call last-tag) -- . ':(exclude)package-lock.json'

.PHONY: sentry-release
sentry-release: ## Create Sentry release and upload JS source maps
	@git tag --force -a "prod_$(TAG_NAME)" -m "prod-release"
	@git push origin --quiet --force "prod_$(TAG_NAME)"
	@SENTRY_ORG=uprzejmie-donosze SENTRY_PROJECT=ud-js \
		./node_modules/.bin/sentry-cli sourcemaps inject ./export/public/js
	@SENTRY_ORG=uprzejmie-donosze SENTRY_PROJECT=ud-js \
		./node_modules/.bin/sentry-cli releases new "prod_$(TAG_NAME)" --finalize
	@SENTRY_ORG=uprzejmie-donosze SENTRY_PROJECT=ud-php \
		./node_modules/.bin/sentry-cli releases new "prod_$(TAG_NAME)" --finalize
	@SENTRY_ORG=uprzejmie-donosze SENTRY_PROJECT=ud-js \
		./node_modules/.bin/sentry-cli sourcemaps upload --org uprzejmie-donosze \
		--project ud-js ./export/public/js

# ── Staging deployment ────────────────────────────────────────────────────────

.PHONY: staging
staging: ## Sync code to staging server and rebuild Docker containers
	@echo "==> Syncing code to $(STAGING_HOST):$(STAGING_PATH)"
	@$(RSYNC) $(RSYNC_STAGING) . $(STAGING_HOST):$(STAGING_PATH)/
	@echo "==> Rebuilding and restarting staging containers"
	#@ssh $(STAGING_HOST) "cd $(STAGING_PATH) && \
		BUILDKIT_PROGRESS=plain docker compose -f services/compose.yml --env-file services/.env.staging \
		-p staging --profile staging up -d --build"

# ── Dev convenience ───────────────────────────────────────────────────────────

.PHONY: dev
dev:
	@docker compose \
        -f services/compose.yml \
        --env-file services/.env.dev \
        -p $@ \
        --profile $@ \
        up --build

.PHONY: emulator-ui
emulator-ui: ## Open Firebase emulator UI in browser
	@open http://localhost:4000

# ── Help ──────────────────────────────────────────────────────────────────────

.PHONY: help
help:
	@printf "\033[36m%-22s  \033[0m %s\n\n" "TARGET" "DESCRIPTION"
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-22s- \033[0m %s\n", $$1, $$2}'

define last-tag
$(shell git show-ref --tags | grep tags/prod_main | tail -n 1 | cut -d" " -f 1)
endef
