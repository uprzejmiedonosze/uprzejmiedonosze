# tools
RSYNC                := rsync
RSYNC_FLAGS          := --human-readable --recursive --exclude 'vendor/bin/*'
HOSTING              := nieradka.net
CYPRESS              := ./node_modules/.bin/cypress
CYPRESS_KEY          := 8a0db00f-b36c-4530-9c82-422b0be32b5b
UNAME                := $(shell uname -s)

ifeq ($(filter darwin%,$(UNAME)),)
    SED_OPTS         := -i ''
else
    SED_OPTS         := "-i''"
endif

# dirs and files 
EXPORT               := export
PUBLIC               := $(EXPORT)/public
DIRS                 := $(PUBLIC)/js $(PUBLIC)/css $(PUBLIC)/api $(PUBLIC)/api/rest $(PUBLIC)/api/config $(EXPORT)/inc $(EXPORT)/inc/integrations $(EXPORT)/inc/middleware $(EXPORT)/templates $(EXPORT)/patronite

CSS_FILES            := $(wildcard src/scss/*.scss)
CSS_HASH             := $(shell cat $(CSS_FILES) | md5sum | cut -b 1-8)
CSS_MINIFIED         := $(PUBLIC)/css/index.css

JS_FILES             := $(wildcard src/js/*.js src/js/*/*.js)
CONFIG_FILES         := $(wildcard src/api/config/*.json)
JS_HASH              := $(shell cat $(JS_FILES) $(CONFIG_FILES) | md5sum | cut -b 1-8)
JS_MINIFIED          := $(PUBLIC)/js/index.js $(PUBLIC)/js/new-app.js $(PUBLIC)/js/firebase.js

HTML_FILES           := $(wildcard src/*.html src/api/*.html)
HTML_PROCESSED       := $(HTML_FILES:src/%.html=export/public/%.html)

CONFIG_FILES         := $(wildcard src/api/config/*.json)
CONFIG_PROCESSED     := $(CONFIG_FILES:src/api/config/%.json=export/public/api/config/%.json)

TWIG_FILES           := $(wildcard src/templates/*.twig)
TWIG_HASH            := $(shell cat $(TWIG_FILES) | md5sum | cut -b 1-8)
TWIG_PROCESSED       := $(TWIG_FILES:src/templates/%=export/templates/%)

PHP_FILES            := $(wildcard src/inc/*.php src/inc/integrations/*.php src/inc/middleware/*.php)
PHP_PROCESSED        := $(PHP_FILES:src/inc/%.php=export/inc/%.php)

MANIFEST             := src/manifest.json
MANIFEST_PROCESSED   := $(PUBLIC)/manifest.json

SITEMAP_PROCESSED    := $(PUBLIC)/sitemap.xml

OTHER_FILES          := src/favicon.ico src/robots.txt src/img src/ads.txt src/sw.js

STAGING_HOST         := staging.uprzejmiedonosze.net
PROD_HOST            := uprzejmiedonosze.net
DEV_HOST             := uprzejmiedonosze.localhost
SHADOW_HOST          := shadow.uprzejmiedonosze.net
HOST                 := $(STAGING_HOST)
HTTPS                := https

BRANCH_ENV           := .branch-env
GIT_BRANCH           := $(shell git rev-parse --abbrev-ref HEAD)
DATE                 := $(shell date '+%Y-%m-%d')
LAST_RUN              = $(shell test -s $(BRANCH_ENV) && cat $(BRANCH_ENV) || echo "clean")
TAG_NAME             := $(shell echo $(GIT_BRANCH)_$(DATE))

.DEFAULT_GOAL        := help

.PHONY: help clean log-from-last-prod cypress confirmation
help: ## Displays this help.
	@printf "\033[36m%-22s  \033[0m %s\n\n" "TARGET" "DESCRIPTION"
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-22s- \033[0m %s\n", $$1, $$2}'

dev: ## Refresh src files in Docker image
	@$(MAKE) --warn-undefined-variables dev-sequential -j

dev-sequential: HOST := $(DEV_HOST)
dev-sequential: HTTPS := http
dev-sequential: $(DIRS) export
	@echo "==> Refreshing sources"
	@cp uprzejmiedonosze.localhost-firebase-adminsdk.json $(EXPORT)
	@$(RSYNC) -r vendor $(EXPORT)

dev-run: HOST := $(DEV_HOST)
dev-run: HTTPS := http
dev-run: $(DIRS) export dev ## Building and running docker image
	@echo "==> Building docker"
	@make --warn-undefined-variables --directory docker build
	@echo "==> Running docker image"
	@make --warn-undefined-variables --directory docker runi

staging: ## Copy files to staging server.
	@$(MAKE) --warn-undefined-variables staging-sequential -j

staging-sequential: HOST := $(STAGING_HOST)
staging-sequential: $(DIRS) export
	@echo "==> Copying files and dirs for $@"
	@$(RSYNC) $(RSYNC_FLAGS) $(EXPORT)/* $(HOSTING):/var/www/$(HOST)/webapp
	@$(RSYNC) $(RSYNC_FLAGS) vendor $(HOSTING):/var/www/$(HOST)/
	@#ssh $(HOSTING) "xtail /var/log/uprzejmiedonosze.net/staging.log"

shadow: ## Copy files to shadow server.
	@$(MAKE) --warn-undefined-variables shadow-sequential -j

shadow-sequential: HOST := $(SHADOW_HOST)
shadow-sequential: $(DIRS) export
	@echo "==> Copying files and dirs for $@"
	@$(RSYNC) $(RSYNC_FLAGS) $(EXPORT)/* $(HOSTING):/var/www/$(HOST)/webapp
	@$(RSYNC) $(RSYNC_FLAGS) vendor $(HOSTING):/var/www/$(HOST)/

prod: HOST := $(PROD_HOST)
prod: check-branch-main check-git-clean cypress clean $(DIRS) export ## Copy files to prod server.
	@echo "==> Copying files and dirs for $@"
	@git tag --force -a "prod_$(TAG_NAME)" -m "release na produkcji"
	@git push origin --quiet --force "prod_$(TAG_NAME)"
	@$(RSYNC) $(RSYNC_FLAGS) $(EXPORT)/* $(HOSTING):/var/www/$(HOST)/webapp
	@$(RSYNC) $(RSYNC_FLAGS) vendor $(HOSTING):/var/www/$(HOST)/
	$(sentry-release)
	@make clean

quickfix:  HOST := $(PROD_HOST)
quickfix: check-branch-main check-git-clean diff-from-last-prod confirmation clean npm-install $(DIRS) export ## Quickfix on production
	@echo "==> Copying files and dirs for $@"
	@git tag --force -a "prod_$(TAG_NAME)" -m "quickfix na produkcji"
	@#git push origin --quiet --force "prod_$(TAG_NAME)"
	@$(RSYNC) $(RSYNC_FLAGS) $(EXPORT)/* $(HOSTING):/var/www/$(HOST)/webapp
	$(sentry-release)
	@make clean

confirmation:
	@tput setaf 160 && \
		echo "\nPRODUCTION QUICKFIX!!" && \
		tput sgr0 && \
		echo "\nAre you sure[yes/N]" && \
		read ans && \
		[ $${ans:-N} = yes ]

.PHONY: npm-install
npm-install:
	@npm install

$(EXPORT)/config.php:
	@test -s config.php && cp config.php $(EXPORT)/ || touch $(EXPORT)/config.php

export: $(DIRS) process-sitemap minify $(EXPORT)/config.php $(PUBLIC)/api/rest/index.php ## Exports files for deployment.
	@echo "==> Exporting"
	@echo "$(GIT_BRANCH)|$(HOST)" > $(BRANCH_ENV)
	@cp -r $(OTHER_FILES) $(PUBLIC)/
	@cp -r src/tools $(EXPORT)/

cypress: npm-install
	@echo "==> Testing staging"
	@$(MAKE) clean
	@$(MAKE) --warn-undefined-variables staging -j
	@$(CYPRESS) run --record --key $(CYPRESS_KEY)

cypress-local:
	@echo "==> Testing local"
	@CYPRESS_BASE_URL=http://uprzejmiedonosze.localhost $(CYPRESS) open --env DOCKER=1

.PHONY: api
api: minify-config $(EXPORT)/config.php
	@[ ! -L db ] && ln -s docker/db . || true
	@[ ! -L src/config.php ] && ln -s export/config.php src || true
	@[ ! -L src/public ] && ln -s export/public src || true
	@[ ! -L inc ] && ln -s src/inc . || true
	@php -S localhost:8080 -t src/api/rest

check-branch: ## Detects environment and active branch changes
	@test "$(LAST_RUN)" = "clean" -o "$(LAST_RUN)" = "$(GIT_BRANCH)|$(HOST)" \
		|| ( echo "Branch or env change detected. Was [$(LAST_RUN)] now is [$(GIT_BRANCH)|$(HOST)]. Clean-up first." \
		&& exit 1 )

check-git-clean: ## Checks if GIT repository has uncommited changes
	@echo "==> Checking if the repo is clean"
	@test "$(shell git status | grep 'nothing to commit' | wc -l)" -eq 1 || ( echo "There are uncommitted changes." && exit 1 )

check-branch-main: ## Checks if GIT is on branch main
	@echo "==> Checking if current branch is main"
	@test "$(shell git status | grep 'origin/main' | wc -l)" -eq 1 || ( echo "Not on branch main." && exit 1 )

check-branch-staging: ## Checks if GIT is on branch staging
	@echo "==> Checking if current branch is staging"
	@test "$(shell git status | grep 'origin/staging' | wc -l)" -eq 1 || ( echo "Not on branch staging." && exit 1 )

minify: check-branch minify-css minify-js process-html minify-config process-php process-twig process-manifest ## Minifies CSS and JS, processing PHP, HTML, TWIG and manifest.json files.
minify-css: $(DIRS) $(CSS_FILES) $(CSS_MINIFIED)
minify-js: $(DIRS) $(JS_FILES) $(CONFIG_FILES) $(JS_MINIFIED)
process-html: $(DIRS) $(HTML_FILES) $(HTML_PROCESSED)
minify-config: $(DIRS) $(CONFIG_FILES) $(CONFIG_PROCESSED)
process-php: $(DIRS) $(PHP_FILES) $(PHP_PROCESSED)
process-twig: $(DIRS) $(TWIG_FILES) $(TWIG_PROCESSED)
process-manifest: $(DIRS) $(MANIFEST) $(MANIFEST_PROCESSED)
process-sitemap: $(DIRS) $(SITEMAP_PROCESSED)

clean: ## Removes minified CSS and JS files.
	@echo "==> Cleaning"
	@rm -rf $(EXPORT)
	@rm -f $(BRANCH_ENV)
	@rm -rf .parcel-cache/ .mypy_cache/

# Generics
$(CSS_MINIFIED): src/scss/index.scss $(CSS_FILES); @echo '==> Minifying $< to $@'
	@rm -rf .parcel-cache
	@./node_modules/.bin/parcel build --no-optimize --no-source-maps --dist-dir $(dir $@) $< ;
	@if [ "$(HOST)" != "$(PROD_HOST)" ]; then \
		if [ "$(HOST)" = "$(SHADOW_HOST)" ]; then \
			sed $(SED_OPTS) 's/#009C7F/#ff4081/gi' $@ ; \
		else \
			sed $(SED_OPTS) 's/#009C7F/#0088bb/gi' $@ ; \
		fi; \
	fi;

$(EXPORT)/public/js/%.js: src/js/%.js $(JS_FILES); $(call echo-processing,$<)
	@./node_modules/.bin/parcel build --public-url "/js" --dist-dir $(dir $@) $<

$(EXPORT)/public/%.html: src/%.html; $(call echo-processing,$<)
	$(lint)
	$(replace)

$(EXPORT)/public/api/api.html: src/api/api.html; $(call echo-processing,$<)
	$(lint)
	$(replace)
	$(replace-inline)

$(EXPORT)/public/api/config/%.json: src/api/config/%.json; $(call echo-processing,$<)
	@jq -c . < $< > $@

$(EXPORT)/public/api/config/sm.json: src/api/config/sm.json; @echo '==> Validating $<'
	@node ./tools/sm-parser.js $< $@

$(EXPORT)/inc/%.php: src/inc/%.php; $(call echo-processing,$<)
	$(lint)
	$(replace)

$(EXPORT)/inc/PDFGenerator.php: src/inc/PDFGenerator.php $(TWIG_FILES); $(call echo-processing,$<)
	$(lint)
	$(replace)
	$(replace-inline)

$(EXPORT)/inc/include.php: src/inc/include.php $(TWIG_FILES); $(call echo-processing,$<)
	$(lint)
	$(replace)
	$(replace-inline)

$(EXPORT)/inc/utils.php: src/inc/utils.php; $(call echo-processing,$<)
	$(lint)
	$(replace)
	$(replace-inline)

$(PUBLIC)/api/rest/index.php: src/api/rest/index.php; $(call echo-processing,$<)
	$(lint)
	$(replace)
	$(replace-inline)

$(EXPORT)/templates/base.html.twig: src/templates/base.html.twig $(JS_FILES) $(CSS_FILES)
	$(call echo-processing,$<)
	$(lint-twig)
	$(replace)
	$(replace-inline)

$(EXPORT)/templates/nowe-zgloszenie.html.twig: src/templates/nowe-zgloszenie.html.twig $(JS_FILES)
	$(call echo-processing,$<)
	$(lint-twig)
	$(replace)
	$(replace-inline)

$(EXPORT)/templates/changelog.html.twig: src/templates/changelog.html.twig
	$(call echo-processing,$<)
	$(lint-twig)
	$(replace)
	$(replace-inline)

$(EXPORT)/templates/%: src/templates/%; $(call echo-processing,$<)
	$(lint-twig)
	$(replace)
	$(replace-inline)

$(MANIFEST_PROCESSED): $(MANIFEST); $(call echo-processing,$<)
	$(replace)

update-libs: ; @echo 'Updating PHP and JS libraries'
	@composer update
	@npm update && npm install

$(SITEMAP_PROCESSED): src/templates/*.html.twig ; @echo '==> Generating sitemap.xml'
	@echo '<urlset \n'\
    	'\txmlns="http://www.sitemaps.org/schemas/sitemap/0.9"\n'\
    	'\txmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"\n'\
		'\txsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9 http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd">\n' \
		> $(SITEMAP_PROCESSED)
	@for PAGE in $$(grep --files-with-matches "SITEMAP-PRIORITY" $^); do \
		PRIO=$$(grep "SITEMAP-PRIORITY" $$PAGE | sed 's/[^0-9\.]//g'); \
		URL=$$(echo $$PAGE | sed -e 's#\(.*/\)##g' -e 's/.twig//' -e 's/index.html//'); \
		MOD_DATE=$$(stat -c '%y' $$PAGE | sed 's/ .*//'); \
		echo "<url>\n\t"\
			"<loc>$(HTTPS)://$(HOST)/$$URL</loc>\n\t" \
			"<lastmod>$$MOD_DATE</lastmod>\n\t" \
			"<priority>$$PRIO</priority>\n"\
			"</url>" >> $(SITEMAP_PROCESSED); \
	done
	@echo '</urlset>\n' >> $(SITEMAP_PROCESSED)	

tail:
	@LOG="$(TAG_NAME).log"; \
		echo "tail -f $${LOG}"; \
		ssh $(HOSTING) "tail -f /var/log/uprzejmiedonosze.net/$${LOG}"


# Patronite

$(EXPORT)/patronite/%.csv: $(EXPORT)/patronite
	@curl --silent -X GET \
		-H "Authorization: token $(PATRONITE_TOKEN)" \
		-H "Content-Type: application/json" \
		https://patronite.pl/author-api/patrons/$* \
		| jq '.results[] | .id' > $@

$(EXPORT)/patronite/patronite.json: $(EXPORT)/patronite/active.csv $(EXPORT)/patronite/inactive.csv
	@jq -c -n '{active:$$active, inactive:$$inactive}' \
		--slurpfile active export/patronite/active.csv \
		--slurpfile inactive export/patronite/inactive.csv \
		> $@

$(EXPORT)/%: ; @echo "==> Creating $@"
	@mkdir -p $@

# Utils

define echo-processing
	@tput setaf 245 && echo "  - processing $1" && tput sgr0
endef

define replace
@sed -e 's/%HOST%/$(HOST)/g' -e 's/%HTTPS%/$(HTTPS)/g' $< > $@
endef

define replace-inline
@sed $(SED_OPTS) -e 's/%JS_HASH%/$(JS_HASH)/g' \
   -e 's/%CSS_HASH%/$(CSS_HASH)/g' \
	 -e 's/%TWIG_HASH%/$(TWIG_HASH)/g' \
	 -e 's/%VERSION%/$(TAG_NAME)/g' $@
endef

ifeq ($(HOST),$(PROD_HOST))
define lint
@set -o pipefail && php -l $< | grep -v "^$$" | ( grep -v "No syntax errors detected" || true )
@./vendor/phpmd/phpmd/src/bin/phpmd $< text cleancode,codesize,controversial,design,naming,unusedcode --ignore-errors-on-exit
endef
else
define lint
endef
endif

define lint-twig
@./vendor/bin/twig-linter lint --no-interaction --quiet $< || ./vendor/bin/twig-linter lint --no-interaction $<
endef

define sentry-release
@SENTRY_ORG=uprzejmie-donosze SENTRY_PROJECT=ud-js ./node_modules/.bin/sentry-cli releases new "prod_$(TAG_NAME)" --finalize
@SENTRY_ORG=uprzejmie-donosze SENTRY_PROJECT=ud-php ./node_modules/.bin/sentry-cli releases new "prod_$(TAG_NAME)" --finalize
@SENTRY_ORG=uprzejmie-donosze SENTRY_PROJECT=ud-js ./node_modules/.bin/sentry-cli releases files "prod_$(TAG_NAME)" upload-sourcemaps export/public/js src/js/jquery-1.12.4.min.map
endef

# GIT

log-from-last-prod: ## Show list of commit messages from last prod release till now
	@git log --color --pretty=format:"%cn %ci %s" HEAD...$(call last-tag)

diff-from-last-prod: ## Show list of commit messages from last prod release till now
	@git diff --histogram --color-words $(call last-tag)

define last-tag
$(shell git show-ref --tags | grep tags/prod_main | tail -n 1 | cut -d" " -f 1)
endef
