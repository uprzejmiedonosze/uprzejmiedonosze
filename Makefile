# tools
YUI_COMPRESSOR       := java -jar tools/yuicompressor-2.4.8.jar
YUI_COMPRESSOR_FLAGS := --charset utf-8 --line-break 72
BABEL                := babel-minify
RSYNC                := rsync
RSYNC_FLAGS          := --human-readable --recursive --exclude 'vendor/bin/*'
HOSTING              := nieradka.net
CYPRESS              := ./node_modules/cypress/bin/cypress
CYPRESS_KEY          := 8a0db00f-b36c-4530-9c82-422b0be32b5b

ifeq ($(filter darwin%,${OSTYPE}),)
    SED_OPTS         := -i ''
else
    SED_OPTS         := "-i''"
endif

# dirs and files 
EXPORT               := export
PUBLIC               := $(EXPORT)/public
DIRS                 := $(PUBLIC)/js $(PUBLIC)/css $(PUBLIC)/api $(PUBLIC)/api/config $(EXPORT)/inc $(EXPORT)/inc/integrations $(EXPORT)/templates

CSS_FILES            := $(wildcard src/css/*.css)
CSS_HASH             := $(shell cat $(CSS_FILES) | md5sum | cut -b 1-8)
CSS_MINIFIED         := $(CSS_FILES:src/%.css=export/public/%-$(CSS_HASH).css)
CSS_MAP_IMAGE        := $(shell base64 src/img/map-circle.png)

JS_FILES             := $(wildcard src/js/*.js)
JS_HASH              := $(shell cat $(JS_FILES) | md5sum | cut -b 1-8)
JS_MINIFIED          := $(JS_FILES:src/%.js=export/public/%-$(JS_HASH).js)

HTML_FILES           := $(wildcard src/*.html src/api/*.html)
HTML_PROCESSED       := $(HTML_FILES:src/%.html=export/public/%.html)

CONFIG_FILES         := $(wildcard src/api/config/*.json)
CONFIG_PROCESSED     := $(CONFIG_FILES:src/api/config/%.json=export/public/api/config/%.json)

TWIG_FILES           := $(wildcard src/templates/*.twig)
TWIG_HASH            := $(shell cat $(TWIG_FILES) | md5sum | cut -b 1-8)
TWIG_PROCESSED       := $(TWIG_FILES:src/templates/%=export/templates/%)

PHP_FILES            := $(wildcard src/inc/*.php src/inc/integrations/*.php)
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
GIT_DATE             := $(shell git log -1 --date=format:"%Y-%m-%d" --format="%ad")
LAST_RUN              = $(shell test -s $(BRANCH_ENV) && cat $(BRANCH_ENV) || echo "clean")
TAG_NAME             := $(shell echo $(GIT_BRANCH)_$(GIT_DATE))

.DEFAULT_GOAL        := help

.PHONY: help clean log-from-last-prod cypress confirmation
help: ## Displays this help.
	@printf "\033[36m%-22s  \033[0m %s\n\n" "TARGET" "DESCRIPTION"
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-22s- \033[0m %s\n", $$1, $$2}'

dev: ## Refresh src files in Docker image
	@$(MAKE) dev-sequential -j

dev-sequential: HOST := $(DEV_HOST)
dev-sequential: HTTPS := http
dev-sequential: $(DIRS) export
	@echo "==> Refreshing sources"
	@cp uprzejmiedonosze.localhost-firebase-adminsdk.json $(EXPORT)

dev-run: HOST := $(DEV_HOST)
dev-run: HTTPS := http
dev-run: $(DIRS) export dev ## Building and running docker image
	@echo "==> Building docker"
	@make --directory docker build
	@echo "==> Running docker image"
	@make --directory docker runi

staging: ## Copy files to staging server.
	$(MAKE) staging-sequential -j

staging-sequential: HOST := $(STAGING_HOST)
staging-sequential: $(DIRS) export
	@echo "==> Copying files and dirs for $@"
	@$(RSYNC) $(RSYNC_FLAGS) $(EXPORT)/* $(HOSTING):/var/www/$(HOST)/webapp
	$(create-symlink)
	@#ssh $(HOSTING) "xtail /var/log/uprzejmiedonosze.net/staging.log"

shadow: ## Copy files to shadow server.
	$(MAKE) shadow-sequential -j

shadow-sequential: HOST := $(SHADOW_HOST)
shadow-sequential: $(DIRS) export
	@echo "==> Copying files and dirs for $@"
	@$(RSYNC) $(RSYNC_FLAGS) $(EXPORT)/* $(HOSTING):/var/www/$(HOST)/webapp
	$(create-symlink)

prod: HOST := $(PROD_HOST)
prod: cypress check-branch-master check-git-clean clean $(DIRS) export ## Copy files to prod server.
	@echo "==> Copying files and dirs for $@"
	@git tag --force -a "prod_$(TAG_NAME)" -m "release na produkcji"
	@git push origin --quiet --force "prod_$(TAG_NAME)"
	@$(RSYNC) $(RSYNC_FLAGS) $(EXPORT)/* $(HOSTING):/var/www/$(HOST)/webapp
	$(create-symlink)
	@make clean

quickfix:  HOST := $(PROD_HOST)
quickfix: diff-from-last-prod confirmation check-branch-master check-git-clean clean $(DIRS) export ## Quickfix on production
	@echo "==> Copying files and dirs for $@"
	@git tag --force -a "prod_$(TAG_NAME)" -m "quickfix na produkcji"
	@git push origin --quiet --force "prod_$(TAG_NAME)"
	@$(RSYNC) $(RSYNC_FLAGS) $(EXPORT)/* $(HOSTING):/var/www/$(HOST)/webapp
	$(create-symlink)
	@make clean

confirmation:
	@tput setaf 160 && \
		echo "\nPRODUCTION QUICKFIX!!" && \
		tput sgr0 && \
		echo "\nAre you sure[yes/N]" && \
		read ans && \
		[ $${ans:-N} = yes ]

export: $(DIRS) process-sitemap minify ## Exports files for deployment.
	@echo "==> Exporting"
	@echo "$(GIT_BRANCH)|$(HOST)" > $(BRANCH_ENV)
	@cp -r $(OTHER_FILES) $(PUBLIC)/
	@cp -r lib vendor src/*.php src/tools $(EXPORT)/
	@test -s config.php && cp config.php $(EXPORT)/ || touch $(EXPORT)/config.php

cypress:
	@echo "==> Testing staging"
	@$(MAKE) clean
	@$(MAKE) staging -j
	@$(CYPRESS) run --record --key $(CYPRESS_KEY)

check-branch: ## Detects environment and active branch changes
	@test "$(LAST_RUN)" = "clean" -o "$(LAST_RUN)" = "$(GIT_BRANCH)|$(HOST)" \
		|| ( echo "Branch or env change detected. Was [$(LAST_RUN)] now is [$(GIT_BRANCH)|$(HOST)]. Clean-up first." \
		&& exit 1 )

check-git-clean: ## Checks if GIT repository has uncommited changes
	@echo "==> Checking if the repo is clean"
	@test "$(shell git status | grep 'nothing to commit' | wc -l)" -eq 1 || ( echo "There are uncommitted changes." && exit 1 )

check-branch-master: ## Checks if GIT is on branch master
	@echo "==> Checking if current branch is master"
	@test "$(shell git status | grep 'origin/master' | wc -l)" -eq 1 || ( echo "Not on branch master." && exit 1 )

check-branch-staging: ## Checks if GIT is on branch staging
	@echo "==> Checking if current branch is staging"
	@test "$(shell git status | grep 'origin/staging' | wc -l)" -eq 1 || ( echo "Not on branch staging." && exit 1 )

minify: check-branch minify-css minify-js process-html minify-config process-php process-twig process-manifest ## Minifies CSS and JS, processing PHP, HTML, TWIG and manifest.json files.
minify-css: $(DIRS) $(CSS_FILES) $(CSS_MINIFIED)
minify-js: $(DIRS) $(JS_FILES) $(JS_MINIFIED)
process-html: $(DIRS) $(HTML_FILES) $(HTML_PROCESSED)
minify-config: $(DIRS) $(CONFIG_FILES) $(CONFIG_PROCESSED)
process-php: $(DIRS) $(PHP_FILES) $(PHP_PROCESSED)
process-twig: $(DIRS) $(TWIG_FILES) $(TWIG_PROCESSED)
process-manifest: $(DIRS) $(MANIFEST) $(MANIFEST_PROCESSED)
process-sitemap: $(DIRS) $(SITEMAP_PROCESSED)

clean: ## Removes minified CSS and JS files.
	@echo "==> Cleaning"
	@rm -rf $(EXPORT)/*
	@rm -f $(BRANCH_ENV)

# Generics
export/public/css/%-$(CSS_HASH).css: src/css/%.css; @echo '==> Minifying $< to $@'
	@if [ "$(HOST)" = "$(PROD_HOST)" ]; then \
		$(YUI_COMPRESSOR) $(YUI_COMPRESSOR_FLAGS) --type css $< > $@ ; \
	elif [ "$(HOST)" = "$(SHADOW_HOST)" ]; then \
		sed 's/#009C7F/#ff4081/g' $< > $@ ; \
	else \
		sed 's/#009C7F/#0088bb/g' $< > $@ ; \
	fi;
	@sed $(SED_OPTS) 's%{{MAP_CIRCLE}}%$(CSS_MAP_IMAGE)%' $@

export/public/js/%-$(JS_HASH).js: src/js/%.js; @echo '==> Minifying $< to $@'
	@if [ "$(HOST)" = "$(PROD_HOST)" ] && ( ! grep -q min <<<"$<" ); then \
		$(BABEL) $< > $@ ;\
	else \
		cp $< $@ ; \
	fi;
	$(replace-inline)

export/public/%.html: src/%.html $(CSS_FILES) $(JS_FILES); $(call echo_processing,$<)
	$(lint)
	$(replace)

export/public/api/config/%.json: src/api/config/%.json; $(call echo_processing,$<)
	@jq -c . < $< > $@

export/public/api/config/sm.json: src/api/config/sm.json; @echo '==> Validating $<'
	@jq '.[].address[2]?' < $< | grep -ve "\d\d-\d\d\d .*" | grep -v "null" || echo "(v) $< postal adddresses OK"
	@jq '.[].email?' < $< | grep -ve "@" | grep -ve "null" || echo "(v) $< email addresses OK"
	@jq '.[].api' < $< | sort | uniq | egrep -v '^("Mail"|"Poznan"|null)' || echo "(v) $< API values OK"
	@jq -c . < $< > $@

export/inc/%.php: src/inc/%.php $(CSS_FILES) $(JS_FILES); $(call echo_processing,$<)
	$(lint)
	$(replace)

export/templates/%: src/templates/% $(CSS_FILES) $(JS_FILES); $(call echo_processing,$<)
	$(lint-twig)
	$(replace)

$(MANIFEST_PROCESSED): $(MANIFEST); $(call echo_processing,$<)
	$(replace)

$(EXPORT)/%: ; @echo "==> Creating $@"
	@mkdir -p $@

update-libs: ; @echo 'Updating PHP and JS libraries'
	@composer update
	@npm update && npm install
	@cp -f node_modules/blueimp-load-image/js/*js src/js/
	@cp -f node_modules/lazysizes/lazysizes.min.js src/js/
	@cp -f node_modules/luxon/build/global/luxon.min.js src/js/

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

# Utils

define echo_processing
	@tput setaf 245 && echo "  - processing $1" && tput sgr0
endef

define replace
@sed -e 's/%HOST%/$(HOST)/g' -e 's/%HTTPS%/$(HTTPS)/g' $< > $@
$(replace-inline)
endef

define replace-inline
@sed $(SED_OPTS) -e 's/%JS_HASH%/$(JS_HASH)/g' \
	 -e 's/%CSS_HASH%/$(CSS_HASH)/g' \
	 -e 's/%TWIG_HASH%/$(TWIG_HASH)/g' \
	 -e 's/%VERSION%/$(TAG_NAME)/g' $@
endef

define lint
@! php -l $< | grep -v "No syntax errors detected"
endef

define lint-twig
@~/.composer/vendor/bin/twig-lint lint --quiet $< || ~/.composer/vendor/bin/twig-lint lint $<
endef

define create-symlink
@echo "==> Creating a symlink in logs directory [$(TAG_NAME).log] -> [$@.log]"
@curl $(HTTPS)://$(HOST)/api/api.html?action=initLogs
@ssh $(HOSTING) "cd /var/log/uprzejmiedonosze.net && ln -fs $(TAG_NAME).log $@.log"
endef

# GIT

log-from-last-prod: ## Show list of commit messages from last prod release till now
	@git log --color --pretty=format:"%cn %ci %s" HEAD...$(call last-tag)

diff-from-last-prod: ## Show list of commit messages from last prod release till now
	@git diff --histogram --color-words $(call last-tag)

define last-tag
$(shell git show-ref --tags | grep tags/prod_ | tail -n 1 | cut -d" " -f 1)
endef
