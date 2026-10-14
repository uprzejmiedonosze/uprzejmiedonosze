# tools
RSYNC      := rsync
CYPRESS    := ./node_modules/.bin/cypress
GIT_BRANCH := $(shell git rev-parse --abbrev-ref HEAD)
DATE       := $(shell date '+%Y-%m-%d')
TAG_NAME   := $(shell echo $(GIT_BRANCH)_$(DATE))

# Docker/GHCR deployment: images are built locally and pushed to GHCR
# (scripts/build-push.sh); the host only pulls (scripts/deploy.sh).
DEPLOY_HOSTING    := uprzejmiedonosze.net
DEPLOY_DIR        := /opt/uprzejmiedonosze
DEPLOY_DIR_SHADOW := /opt/uprzejmiedonosze-shadow

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
init-db-staging: ## Wipe and re-initialize the staging SQLite database (DELETES all staging data)
	@ssh $(DEPLOY_HOSTING) "docker exec staging-webapp-srv-1 sqlite3 /var/www/uprzejmiedonosze.net/db/store.sqlite \
		-init /var/www/uprzejmiedonosze.net/webapp/sql/init_empty.sql"

# Linting and unit tests also run in the Docker builder stage (services/webapp/Dockerfile).

# ── Testing ───────────────────────────────────────────────────────────────────

.PHONY: test
test: ## Run phpunit tests in a throwaway builder-test container (works alongside `make dev`)
	@docker compose -f services/compose.yml $(if $(wildcard services/.env.dev),--env-file services/.env.dev,) \
		-p uprzejmiedonosze-$@ --profile $@ run --rm --build builder-test; \
		status=$$?; \
		docker compose -f services/compose.yml -p uprzejmiedonosze-$@ --profile $@ down; \
		exit $$status

.PHONY: lint-css
lint-css: ## Stylelint on src/scss (tokens instead of hardcoded colors; legacy files only warn)
	@tools/node_modules/.bin/stylelint --config tools/stylelint.config.mjs --quiet "src/scss/**/*.scss"

.PHONY: cypress-local
cypress-local: ## Run Cypress tests against local dev environment
	@[ -d tools/node_modules ] || (cd tools && npm install)
	@cd tools && CYPRESS_BASE_URL=http://127.0.0.1 CYPRESS_DOCKER=1 $(CYPRESS) run --e2e

.PHONY: cypress-staging
cypress-staging: ## Run Cypress tests against staging (config defaults), in a visible browser
	@[ -d tools/node_modules ] || (cd tools && npm install)
	@cd tools && $(CYPRESS) run --e2e --headed

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

.PHONY: confirmation
confirmation:
	@tput setaf 160 && \
		echo "\nPRODUCTION DEPLOY!!" && \
		tput sgr0 && \
		echo "\nAre you sure[yes/N]" && \
		read ans && \
		[ $${ans:-N} = yes ]

.PHONY: log-from-last-prod
log-from-last-prod: ## Commits since last prod release
	@git log --color --pretty=format:"%cn %ci %s" HEAD...$(call last-tag)

.PHONY: diff-from-last-prod
diff-from-last-prod: ## Diff since last prod release
	@git diff --histogram --color-words $(call last-tag) -- . ':(exclude)package-lock.json' ':(exclude)tools/package-lock.json'

# ── Deployment (Docker images via GHCR) ──────────────────────────────────────

.PHONY: deploy-staging
deploy-staging: check-git-clean ## Build+push staging images to GHCR, deploy on the host
	@echo "==> Building and pushing staging images"
	@bash scripts/build-push.sh staging "$(shell git rev-parse --short HEAD)"
	@$(RSYNC) --human-readable services/.env.staging $(DEPLOY_HOSTING):$(DEPLOY_DIR)/services/.env.staging
	@echo "==> Deploying staging on $(DEPLOY_HOSTING)"
	@ssh $(DEPLOY_HOSTING) 'cd $(DEPLOY_DIR) && bash scripts/deploy.sh staging "$(shell git rev-parse --short HEAD)"'
	@$(MAKE) --no-print-directory deploy-healthcheck URL=https://staging.uprzejmiedonosze.net/

.PHONY: deploy-prod
deploy-prod: check-branch-main check-git-clean diff-from-last-prod confirmation ## Build+push prod images to GHCR, deploy on the host, tag+Sentry release
	@echo "==> Building and pushing prod images (with Sentry release)"
	@bash scripts/build-push.sh prod "prod_$(TAG_NAME)" --sentry
	@$(RSYNC) --human-readable services/.env.prod $(DEPLOY_HOSTING):$(DEPLOY_DIR)/services/.env.prod
	@echo "==> Deploying prod on $(DEPLOY_HOSTING)"
	@ssh $(DEPLOY_HOSTING) 'cd $(DEPLOY_DIR) && bash scripts/deploy.sh prod "prod_$(TAG_NAME)"'
	@$(MAKE) --no-print-directory deploy-healthcheck URL=https://uprzejmiedonosze.net/
	@git tag --force -a "prod_$(TAG_NAME)" -m "prod-release"
	@git push origin --quiet --force "prod_$(TAG_NAME)"

.PHONY: deploy-shadow
deploy-shadow: check-git-clean ## Build+push shadow images to GHCR, deploy on the host (bare app, like staging)
	@echo "==> Building and pushing shadow images"
	@bash scripts/build-push.sh shadow "$(shell git rev-parse --short HEAD)"
	@$(RSYNC) --human-readable services/.env.shadow $(DEPLOY_HOSTING):$(DEPLOY_DIR_SHADOW)/services/.env.shadow
	@echo "==> Deploying shadow on $(DEPLOY_HOSTING)"
	@ssh $(DEPLOY_HOSTING) 'cd $(DEPLOY_DIR_SHADOW) && bash scripts/deploy.sh shadow "$(shell git rev-parse --short HEAD)"'
	@$(MAKE) --no-print-directory deploy-healthcheck URL=https://shadow.uprzejmiedonosze.net/

.PHONY: deploy-healthcheck
deploy-healthcheck:
	@status=""; \
	for i in 1 2 3 4 5; do \
		status=$$(curl -s -o /dev/null -w "%{http_code}" --max-time 5 "$(URL)" || echo "000"); \
		[ "$$status" = "200" ] && break; \
		sleep 3; \
	done; \
	echo "$(URL) HTTP status: $$status"; \
	[ "$$status" = "200" ] || ( echo "Deploy check failed: $(URL) returned $$status" && exit 1 )

# ── Dev convenience ───────────────────────────────────────────────────────────

.PHONY: dev
dev:
	@docker compose \
        -f services/compose.yml \
        $(if $(wildcard services/.env.dev),--env-file services/.env.dev,) \
        -p uprzejmiedonosze-$@ \
        --profile $@ \
        up --build

.PHONY: dev-stop
dev-stop:
	@docker compose -f services/compose.yml \
		-p uprzejmiedonosze-dev --profile dev down

.PHONY: emulator-ui
emulator-ui: ## Open Firebase emulator UI in browser
	@open http://localhost:4000

.PHONY: mailpit-ui
mailpit-ui: ## Open Mailpit inbox in browser
	@open http://localhost:8025

# ── Help ──────────────────────────────────────────────────────────────────────

.PHONY: help
help:
	@printf "\033[36m%-22s  \033[0m %s\n\n" "TARGET" "DESCRIPTION"
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-22s- \033[0m %s\n", $$1, $$2}'

define last-tag
$(shell git show-ref --tags | grep tags/prod_main | tail -n 1 | cut -d" " -f 1)
endef
