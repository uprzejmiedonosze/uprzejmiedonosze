# tools
YUI_COMPRESSOR       := java -jar tools/yuicompressor-2.4.8.jar
YUI_COMPRESSOR_FLAGS := --charset utf-8 --line-break 72
BABEL                := babel-minify
RSYNC                := rsync
RSYNC_FLAGS          := --human-readable --recursive --exclude 'vendor/bin/*'
HOSTING              := nieradka.net
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

OTHER_FILES          := src/favicon.ico src/robots.txt src/img src/sitemap.xml src/ads.txt src/sw.js

STAGING_HOST         := staging.uprzejmiedonosze.net
PROD_HOST            := uprzejmiedonosze.net
DEV_HOST             := uprzejmiedonosze.localhost
HOST                 := $(STAGING_HOST)
HTTPS                := https

BRANCH_ENV           := .branch-env
GIT_BRANCH           := $(shell git rev-parse --abbrev-ref HEAD)
LAST_RUN              = $(shell test -s $(BRANCH_ENV) && cat $(BRANCH_ENV) || echo "clean")
TAG_NAME             := $(shell echo $(GIT_BRANCH)_`date +%Y-%m-%d_%H.%M.%S`)

.DEFAULT_GOAL        := help

.PHONY: help clean log-from-last-prod
help: ## Displays this help.
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST)  | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s- \033[0m %s\n", $$1, $$2}'

dev: HOST := $(DEV_HOST)
dev: HTTPS := http
dev: $(DIRS) export ## Refresh src files in Docker image
	@echo "==> Refreshing sources"

dev-run: HOST := $(DEV_HOST)
dev-run: HTTPS := http
dev-run: $(DIRS) export dev ## Building and running docker image
	@echo "==> Building docker"
	@make --directory docker build
	@echo "==> Running docker image"
	@make --directory docker runi

staging: HOST := $(STAGING_HOST)
staging: $(DIRS) export ## Copy files to staging server.
	@echo "==> Copying files and dirs for $@"
	@$(RSYNC) $(RSYNC_FLAGS) $(EXPORT)/* $(HOSTING):/var/www/$(HOST)/webapp
	$(create-symlink)

prod: HOST := $(PROD_HOST)
prod: check-branch-master check-git-clean clean $(DIRS) export ## Copy files to prod server.
	@echo "==> Copying files and dirs for $@"
	@git tag -a "prod_$(TAG_NAME)" -m "release na produkcji"
	@git push origin --quiet "prod_$(TAG_NAME)"
	@$(RSYNC) $(RSYNC_FLAGS) $(EXPORT)/* $(HOSTING):/var/www/$(HOST)/webapp
	$(create-symlink)
	@make clean

export: $(DIRS) minify ## Exports files for deployment.
	@echo "==> Exporting"
	@echo "$(GIT_BRANCH)|$(HOST)" > $(BRANCH_ENV)
	@cp -r $(OTHER_FILES) $(PUBLIC)/
	#@cp -r lib vendor src/*.php $(HOST)-firebase-adminsdk.json src/tools $(EXPORT)/
	@cp -r lib vendor src/*.php src/tools $(EXPORT)/

check-branch: ## Detects environment and active branch changes
	@test "$(LAST_RUN)" = "clean" -o "$(LAST_RUN)" = "$(GIT_BRANCH)|$(HOST)" \
		|| ( echo "Branch or env change detected. Was [$(LAST_RUN)] now is [$(GIT_BRANCH)|$(HOST)]. Clean-up first." \
		&& exit 1 )

check-git-clean: ## Checks if GIT repository has uncommited changes
	@test "$(shell git status | grep 'nothing to commit' | wc -l)" -eq 1 || ( echo "There are uncommitted changes." && exit 1 )

check-branch-master: ## Checks if GIT is on branch master
	@test "$(shell git status | grep 'origin/master' | wc -l)" -eq 1 || ( echo "Not on branch master." && exit 1 )

check-branch-staging: ## Checks if GIT is on branch master
	@test "$(shell git status | grep 'origin/staging' | wc -l)" -eq 1 || ( echo "Not on branch staging." && exit 1 )

minify: check-branch minify-css minify-js process-html minify-config process-php process-twig process-manifest ## Minifies CSS and JS, processing PHP, HTML, TWIG and manifest.json files.
minify-css: $(DIRS) $(CSS_FILES) $(CSS_MINIFIED)
minify-js: $(DIRS) $(JS_FILES) $(JS_MINIFIED)
process-html: $(DIRS) $(HTML_FILES) $(HTML_PROCESSED)
minify-config: $(DIRS) $(CONFIG_FILES) $(CONFIG_PROCESSED)
process-php: $(DIRS) $(PHP_FILES) $(PHP_PROCESSED)
process-twig: $(DIRS) $(TWIG_FILES) $(TWIG_PROCESSED)
process-manifest: $(DIRS) $(MANIFEST) $(MANIFEST_PROCESSED)

clean: ## Removes minified CSS and JS files.
	@echo "==> Cleaning"
	@rm -rf $(EXPORT)/*
	@rm -f $(BRANCH_ENV)

# Generics
export/public/css/%-$(CSS_HASH).css: src/css/%.css; @echo '==> Minifying $< to $@'
	@if [ "$(HOST)" = "$(PROD_HOST)" ]; then \
		$(YUI_COMPRESSOR) $(YUI_COMPRESSOR_FLAGS) --type css $< > $@ ; \
	else \
		cp $< $@ ; \
	fi;

export/public/js/%-$(JS_HASH).js: src/js/%.js; @echo '==> Minifying $< to $@'
	@if [ "$(HOST)" = "$(PROD_HOST)" ] && ( ! grep -q min <<<"$<" ); then \
		$(BABEL) $< > $@ ;\
	else \
		cp $< $@ ; \
	fi;
	$(replace-inline)

export/public/%.html: src/%.html $(CSS_FILES) $(JS_FILES); @echo '==> Preprocessing $<'
	$(lint)
	$(replace)

export/public/api/config/%.json: src/api/config/%.json; @echo '==> Preprocessing $<'
	@jq -c . < $< > $@

export/public/api/config/sm.json: src/api/config/sm.json; @echo '==> Validating $<'
	@jq '.[].address[2]?' < $< | grep -ve "\d\d-\d\d\d .*" | grep -v "null" || echo "$< postal adddresses OK"
	@jq '.[].email?' < $< | grep -ve "@" | grep -ve "null" || echo "$< email addresses OK"
	@jq -c . < $< > $@

export/inc/%.php: src/inc/%.php $(CSS_FILES) $(JS_FILES); @echo '==> Preprocessing $<'
	$(lint)
	$(replace)

export/templates/%: src/templates/% $(CSS_FILES) $(JS_FILES); @echo '==> Preprocessing $<'
	$(lint-twig)
	$(replace)

$(MANIFEST_PROCESSED): $(MANIFEST); @echo '==> Preprocessing $<'
	$(replace)

$(EXPORT)/%: ; @echo "==> Creating $@"
	@mkdir -p $@

# Utils

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
@ssh $(HOSTING) "cd /var/log/uprzejmiedonosze.net && ln -fs $(TAG_NAME).log $@.log"
endef

# GIT

log-from-last-prod: ## Show list of commit messages from last prod release till now
	@git log --color --pretty=format:"%cn %ci %s" HEAD...`git show-ref --tags | grep tags/prod_ | tail -n 1 | cut -d" " -f 1`

diff-from-last-prod: ## Show list of commit messages from last prod release till now
	@git diff --histogram --color-words `git show-ref --tags | grep tags/prod_ | tail -n 1 | cut -d" " -f 1`
