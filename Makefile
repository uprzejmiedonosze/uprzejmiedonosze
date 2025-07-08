# tools
RSYNC                := rsync
RSYNC_FLAGS          := --human-readable --recursive --delete --exclude 'vendor/bin/*'
HOSTING              := nieradka.net
CYPRESS              := ./node_modules/.bin/cypress
CYPRESS_KEY          := 8a0db00f-b36c-4530-9c82-422b0be32b5b
UNAME                := $(shell uname -s)

# dirs and files
EXPORT               := export
PUBLIC               := $(EXPORT)/public
DIRS                 := $(PUBLIC)/api $(PUBLIC)/api/rest $(PUBLIC)/api/config $(EXPORT)/inc \
						$(EXPORT)/inc/integrations $(EXPORT)/inc/middleware $(EXPORT)/inc/handlers \
						$(EXPORT)/inc/dataclasses $(EXPORT)/inc/store $(EXPORT)/inc/converters \
						$(EXPORT)/templates

CSS_FILES            := $(wildcard src/scss/*.scss src/scss/*/*.scss)
CSS_HASH             := $(shell cat $(CSS_FILES) | md5sum | cut -b 1-8)
CSS_MINIFIED         := $(PUBLIC)/css/index.css

JS_FILES             := $(wildcard src/js/*.js)
JS_FILES_DEPS        := $(wildcard src/js/*.js src/js/*/*.js)
CONFIG_FILES         := $(wildcard src/api/config/*.json)
JS_HASH              := $(shell cat $(JS_FILES_DEPS) $(CONFIG_FILES) | md5sum | cut -b 1-8)
JS_MINIFIED          := $(JS_FILES:src/js/%.js=export/public/js/%.js)

HTML_FILES           := $(wildcard src/api/*.html)
HTML_PROCESSED       := $(HTML_FILES:src/api/%.html=export/public/api/%.html)

CONFIG_PROCESSED     := $(CONFIG_FILES:src/api/config/%.json=export/public/api/config/%.json)

TWIG_FILES           := $(wildcard src/templates/*.twig)
TWIG_HASH            := $(shell cat $(TWIG_FILES) | md5sum | cut -b 1-8)
TWIG_PROCESSED       := $(TWIG_FILES:src/templates/%=export/templates/%)

PHP_FILES            := $(wildcard src/inc/*.php src/inc/*/*.php)
PHP_PROCESSED        := $(PHP_FILES:src/inc/%.php=export/inc/%.php)

MANIFEST             := src/manifest.json
MANIFEST_PROCESSED   := $(PUBLIC)/manifest.json

SITEMAP_PROCESSED    := $(PUBLIC)/sitemap.xml

OTHER_FILES          := src/favicon.ico src/robots.txt src/ads.txt src/index.php

STAGING_HOST         := staging.uprzejmiedonosze.net
PROD_HOST            := uprzejmiedonosze.net
DEV_HOST             := localhost
SHADOW_HOST          := shadow.uprzejmiedonosze.net
HOST                 := $(STAGING_HOST)
HTTPS                := https

BRANCH_ENV           := .branch-env
GIT_BRANCH           := $(shell git rev-parse --abbrev-ref HEAD)
DATE                 := $(shell date '+%Y-%m-%d')
LAST_RUN              = $(shell test -s $(BRANCH_ENV) && cat $(BRANCH_ENV) || echo "clean")
TAG_NAME             := $(shell echo $(GIT_BRANCH)_$(DATE))

.DEFAULT_GOAL        := help


dev: check-local-configs ## Refresh src files in Docker image
	@$(MAKE) --warn-undefined-variables dev-sequential -j

dev-sequential: HOST := $(DEV_HOST)
dev-sequential: HTTPS := http
dev-sequential: export_minimal
	@echo "==> Refreshing sources"
	@cp localhost-firebase-adminsdk.json $(EXPORT)
	@$(RSYNC) -r vendor $(EXPORT)

.PHONY: check-local-configs
check-local-configs:
	@if [ ! -f config.php ]; then \
		echo "Error: config.php is missing. Please refer to README.md for instructions on how to create it."; \
		exit 1; \
	fi
	@if [ ! -f localhost-firebase-adminsdk.json ]; then \
		echo "Error: localhost-firebase-adminsdk.json is missing. Please refer to README.md for instructions on how to create it."; \
		exit 1; \
	fi

dev-run: HOST := $(DEV_HOST)
dev-run: HTTPS := http
dev-run: $(DIRS) dev ## Building and running Docker image
	@echo "==> Building docker"
	@make --warn-undefined-variables --directory docker build
	@echo "==> Running docker image"
	@make --warn-undefined-variables --directory docker runi

staging: ## Copy files to staging server
	@$(MAKE) --warn-undefined-variables staging-sequential -j

staging-sequential: HOST := $(STAGING_HOST)
staging-sequential: $(DIRS) $(EXPORT)
	@echo "==> Copying files and dirs for $@"
	@$(RSYNC) $(RSYNC_FLAGS) $(EXPORT)/* $(HOSTING):/var/www/$(HOST)/webapp
	@$(RSYNC) $(RSYNC_FLAGS) vendor $(HOSTING):/var/www/$(HOST)/
	@#ssh $(HOSTING) "xtail /var/log/uprzejmiedonosze.net/staging.log"

shadow: ## Copy files to shadow server
	@$(MAKE) --warn-undefined-variables shadow-sequential -j

shadow-sequential: HOST := $(SHADOW_HOST)
shadow-sequential: $(DIRS) exportserver
	@echo "==> Copying files and dirs for $@"
	@$(RSYNC) $(RSYNC_FLAGS) $(EXPORT)/* $(HOSTING):/var/www/$(HOST)/webapp
	@$(RSYNC) $(RSYNC_FLAGS) vendor $(HOSTING):/var/www/$(HOST)/

prod: HOST := $(PROD_HOST)
prod: check-branch-main check-git-clean cypress clean $(DIRS) exportserver ## Copy files to prod server
	@echo "==> Copying files and dirs for $@"
	@git tag --force -a "prod_$(TAG_NAME)" -m "release na produkcji"
	@git push origin --quiet --force "prod_$(TAG_NAME)"
	@$(RSYNC) $(RSYNC_FLAGS) $(EXPORT)/* $(HOSTING):/var/www/$(HOST)/webapp
	@$(RSYNC) $(RSYNC_FLAGS) vendor $(HOSTING):/var/www/$(HOST)/
	$(sentry-release)
	@make clean

quickfix:  HOST := $(PROD_HOST)
quickfix: check-branch-main check-git-clean diff-from-last-prod confirmation clean npm-install $(DIRS) exportserver ## Quickfix on production
	@echo "==> Copying files and dirs for $@"
	@git tag --force -a "prod_$(TAG_NAME)" -m "quickfix na produkcji"
	@git push origin --quiet --force "prod_$(TAG_NAME)"
	@$(RSYNC) $(RSYNC_FLAGS) $(EXPORT)/* $(HOSTING):/var/www/$(HOST)/webapp
	@$(RSYNC) $(RSYNC_FLAGS) vendor $(HOSTING):/var/www/$(HOST)/
	$(sentry-release)
	@make clean

.PHONY: export_minimal
export_minimal: $(DIRS) $(EXPORT)/config.env.php $(PUBLIC)/api/config/police-stations.pjson minify $(EXPORT)/config.php ## Exports files for deployment
	@echo "==> Exporting"
	@echo "$(GIT_BRANCH)|$(HOST)" > $(BRANCH_ENV)
	@cp -r $(OTHER_FILES) $(PUBLIC)/
	@cp -r src/tools $(EXPORT)/
	@cp -r src/sql $(EXPORT)/

$(EXPORT): export_minimal process-sitemap $(PUBLIC)/api/rest/index.php test-phpunit ## Exports files for deployment
	@echo "==> Exporting"

.PHONY: exportserver
exportserver: $(EXPORT) config.prod.php $(PUBLIC)/fail2ban/index.html
	@cp config.prod.php $(EXPORT)

.PHONY: minify
minify: check-branch js css $(EXPORT)/images-index.html minify-config process-php process-twig lint-twig process-manifest ## Processing PHP, HTML, TWIG and manifest.json files
minify-config: $(DIRS) $(CONFIG_FILES) $(CONFIG_PROCESSED)
process-php: $(DIRS) $(PHP_FILES) $(PHP_PROCESSED)
process-twig: $(DIRS) $(TWIG_FILES) $(TWIG_PROCESSED)
process-manifest: $(DIRS) $(MANIFEST) $(MANIFEST_PROCESSED)
process-sitemap: $(DIRS) $(SITEMAP_PROCESSED)

ASSETS := $(wildcard src/img/* src/img/*/*)
$(EXPORT)/images-index.html: src/images-index.html $(ASSETS)
	@(cat src/images-index.html; grep 'src="/img[^"{]\+"' --only-matching --no-filename --recursive --color=never src/templates \
		| sed 's|src="/|<img src="./|' | sed 's|$$| />|' ) | sort | uniq | sponge src/images-index.html
	@$(PARCEL_BUILD_CMD) $(PUBLIC)/img $< ;
	@cp src/images-index.html $@

$(EXPORT)/config.env.php: src/config.env.php
	@cp $< $@

$(EXPORT)/config.php: config.php
	@cp $< $@

src/config.env.php: $(JS_FILES_DEPS) $(TWIG_FILES) $(CONFIG_FILES) $(CSS_FILES)
	@echo "<?php" > $@
	@echo "define('HOST', '$(HOST)');" >> $@
	@echo "define('TWIG_HASH', '$(TWIG_HASH)');" >> $@
	@echo "define('CSS_HASH', '$(CSS_HASH)');" >> $@
	@echo "define('JS_HASH', '$(JS_HASH)');" >> $@
	@echo "define('HTTPS', '$(HTTPS)');" >> $@

src/scss/lib/variables.env.scss:
	@HOST=$(HOST) node ./src/scss/env.js > $@

# Define the PARCEL_BUILD_CMD variable based on the HOST variable
ifeq ($(HOST),$(PROD_HOST))
	PARCEL_BUILD_CMD := ./node_modules/.bin/parcel build --no-cache --no-source-maps --dist-dir
else
    PARCEL_BUILD_CMD := ./node_modules/.bin/parcel build --no-cache --dist-dir
endif

.PHONY: css
css: $(CSS_MINIFIED)
$(CSS_MINIFIED): src/scss/index.scss $(CSS_FILES) src/scss/lib/variables.env.scss; $(call echo-processing,$@ with parcel)
	@$(PARCEL_BUILD_CMD) $(dir $@) $< ;

.PHONY: js
js: $(JS_MINIFIED)
export/public/js/%.js: src/js/%.js $(JS_FILES_DEPS); $(call echo-processing,$@ with parcel)
	@$(PARCEL_BUILD_CMD) $(dir $@) $< ;

$(EXPORT)/public/api/config/sm.json: src/api/config/sm.json $(EXPORT)/public/api/config; $(call echo-processing,$< with node)
	@node ./tools/sm-parser.js $< $@

$(EXPORT)/public/api/config/stop-agresji.json: src/api/config/stop-agresji.json $(EXPORT)/public/api/config; $(call echo-processing,$< with node)
	@node ./tools/sm-parser.js $< $@	

$(EXPORT)/public/api/config/%.json: src/api/config/%.json $(EXPORT)/public/api/config; $(call echo-processing,$<)
	@jq -c . < $< > $@

$(EXPORT)/inc/%.php: src/inc/%.php
	@cp $< $@
	$(lint)
$(PUBLIC)/%.php: src/%.php
	@cp $< $@
	$(lint)
$(PUBLIC)/api/rest/index.php: src/api/rest/index.php
	@cp $< $@
	$(lint)

$(EXPORT)/inc/PDFGenerator.php: src/inc/PDFGenerator.php $(TWIG_FILES)
	@cp $< $@
	$(lint)

$(EXPORT)/inc/include.php: src/inc/include.php $(TWIG_FILES)
	@cp $< $@
	$(lint)

$(PUBLIC)/fail2ban/index.html: export_minimal src/templates/fail2ban.html.twig
	@mkdir -p $(@D)
	@php tools/fail2ban-twig.php > $@

$(EXPORT)/templates/%: src/templates/%; $(call echo-processing,$<)
	@cp $< $@

$(MANIFEST_PROCESSED): $(MANIFEST); $(call echo-processing,$<)
	@cp $< $@

$(SITEMAP_PROCESSED): src/templates/*.html.twig ; $(call echo-processing,$@)
	@(echo '<urlset \n'\
    	'\txmlns="http://www.sitemaps.org/schemas/sitemap/0.9"\n'\
    	'\txmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"\n'\
		'\txsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9 http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd">\n' \
		; \
	for PAGE in $$(grep --files-with-matches "SITEMAP-PRIORITY" $^); do \
		PRIO=$$(grep "SITEMAP-PRIORITY" $$PAGE | sed 's/[^0-9\.]//g'); \
		URL=$$(echo $$PAGE | sed -e 's#\(.*/\)##g' -e 's/.twig//' -e 's/index.html//'); \
		MOD_DATE=$$(stat -c '%y' $$PAGE | sed 's/ .*//'); \
		echo "<url>\n\t"\
			"<loc>$(HTTPS)://$(HOST)/$$URL</loc>\n\t" \
			"<lastmod>$$MOD_DATE</lastmod>\n\t" \
			"<priority>$$PRIO</priority>\n"\
			"</url>" ; \
	done ; \
	echo '</urlset>\n' ) | xmllint --format - > $(SITEMAP_PROCESSED)


$(PUBLIC)/api/config/police-stations.pjson: src/api/config/police-stations.csv $(PUBLIC)/api/config/stop-agresji.json
	$(call echo-processing,$<)
	@php tools/police-stations.php $^ > $@ || (rm -f $@; exit -1)

$(DIRS): ; @echo "==> Creating $@"
	@mkdir -p $@

# PHONY

.PHONY: clean
clean: ## Removes minified CSS and JS files
	@echo "==> Cleaning"
	@rm -rf $(EXPORT)
	@rm -f $(BRANCH_ENV)
	@rm -f src/config.env.php src/scss/lib/variables.env.scss
	@rm -rf .parcel-cache/

.PHONY: help
help:
	@printf "\033[36m%-22s  \033[0m %s\n\n" "TARGET" "DESCRIPTION"
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-22s- \033[0m %s\n", $$1, $$2}'


.PHONY: confirmation
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

.PHONY: update-libs
update-libs: ; @echo 'Updating PHP and JS libraries'
	@composer update
	@npm update && npm install

.PHONY: cypress
cypress: npm-install
	@echo "==> Testing staging"
	@$(MAKE) clean
	@$(MAKE) --warn-undefined-variables staging -j
	@$(CYPRESS) run --record --key $(CYPRESS_KEY)

.PHONY: cypress-local
cypress-local:
	@echo "==> Testing local"
	@CYPRESS_BASE_URL=http://127.0.0.1 $(CYPRESS) run --e2e --env DOCKER=1

.PHONY: api
api: minify-config
	@[ ! -L db ] && ln -s docker/db . || true
	@[ ! -L src/config.php ] && ln -s export/config.php src || true
	@[ ! -L src/public ] && ln -s export/public src || true
	@[ ! -L inc ] && ln -s src/inc . || true
	@php -S localhost:8080 -t src/api/rest

.PHONY: check-branch
check-branch: ## Detects environment and active branch changes
	@test "$(LAST_RUN)" = "clean" -o "$(LAST_RUN)" = "$(GIT_BRANCH)|$(HOST)" \
		|| ( echo "Branch or env change detected. Was [$(LAST_RUN)] now is [$(GIT_BRANCH)|$(HOST)]. Clean-up first." \
		&& exit 1 )

.PHONY: check-git-clean
check-git-clean: ## Checks if GIT repository has uncommited changes
	@echo "==> Checking if the repo is clean"
	@test "$(shell LC_ALL=en_US git status | grep 'nothing to commit' | wc -l)" -eq 1 || ( echo "There are uncommitted changes." && exit 1 )

.PHONY: check-branch-main
check-branch-main: ## Checks if GIT is on branch main
	@echo "==> Checking if current branch is main"
	@test "$(shell LC_ALL=en_US git status | grep 'origin/main' | wc -l)" -eq 1 || ( echo "Not on branch main." && exit 1 )


.PHONY: log-from-last-prod
log-from-last-prod: ## Show list of commit messages from last prod release till now
	@git log --color --pretty=format:"%cn %ci %s" HEAD...$(call last-tag)

.PHONY: diff-from-last-prod
diff-from-last-prod: ## Show diff from last prod release till now
	@git diff --histogram --color-words $(call last-tag) -- . ':(exclude)package-lock.json'

.PHONY: lint-twig
lint-twig: src/templates/*.twig
	@./vendor/bin/twig-linter lint --no-interaction --quiet $^ || ./vendor/bin/twig-linter lint --no-interaction $^

.PHONY: init-db-staging
init-db-staging:
	@ssh nieradka.net "sqlite3 /var/www/staging.uprzejmiedonosze.net/db/store.sqlite < /var/www/staging.uprzejmiedonosze.net/webapp/sql/init_empty.sql"

.PHONY: init-db-dev
init-db-dev:
	@docker exec webapp sqlite3 /var/www/localhost/db/store.sqlite -init /var/www/localhost/webapp/sql/init_empty.sql

# defines

define last-tag
$(shell git show-ref --tags | grep tags/prod_main | tail -n 1 | cut -d" " -f 1)
endef

define sentry-release
@SENTRY_ORG=uprzejmie-donosze SENTRY_PROJECT=ud-js ./node_modules/.bin/sentry-cli releases new "prod_$(TAG_NAME)" --finalize
@SENTRY_ORG=uprzejmie-donosze SENTRY_PROJECT=ud-php ./node_modules/.bin/sentry-cli releases new "prod_$(TAG_NAME)" --finalize
@SENTRY_ORG=uprzejmie-donosze SENTRY_PROJECT=ud-js ./node_modules/.bin/sentry-cli releases files "prod_$(TAG_NAME)" upload-sourcemaps export/public/js src/js/jquery-1.12.4.min.map
endef

define echo-processing
	@tput setaf 245 && echo "  - processing $1" && tput sgr0
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

PWD=$(shell pwd)
lint-php:
	@./vendor/phpmd/phpmd/src/bin/phpmd src/ text \
		cleancode,codesize,controversial,design,naming,unusedcode \
		--minimumpriority 10 --color | \
		sed 's|$(PWD)/||'
	@./vendor/phpmd/phpmd/src/bin/phpmd src/ text \
		cleancode,codesize,controversial,design,naming,unusedcode | wc -l

.PHONY: test-phpunit
.ONESHELL: test-phpunit
test-phpunit: MEMCACHED := $(shell curl -m3 localhost:11211 2>&1 | grep -c Fail || true)
test-phpunit: $(PUBLIC)/api/config/sm.json $(PUBLIC)/api/config/stop-agresji.json $(PUBLIC)/api/config/police-stations.pjson \
	$(PUBLIC)/api/config/police-stations.pjson process-php minify-config $(EXPORT)/config.php $(EXPORT)/config.env.php
	@echo "==> Testing phpunit"
	@test $(MEMCACHED) -eq 1 && (echo "    starting memcached"; memcached &); sleep 1 || true
	@trap 'echo "    reverting DB"; git restore docker/db/store.sqlite; exit' INT TERM EXIT; \
	./vendor/phpunit/phpunit/phpunit --display-deprecations --no-output tests || \
	./vendor/phpunit/phpunit/phpunit --display-deprecations tests


.PHONY: memcached
memcached: MEMCACHED := $(shell curl localhost:11211 2>&1 | grep -c Fail || true)
