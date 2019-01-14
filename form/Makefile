# tools
YUI_COMPRESSOR       = java -jar tools/yuicompressor-2.4.8.jar
YUI_COMPRESSOR_FLAGS = --charset utf-8 --line-break 72
RSYNC                = rsync
RSYNC_FLAGS          = --human-readable --recursive --exclude 'vendor/bin/jp.php'
HOSTING              = nieradka.net

# dirs and files 
EXPORT               = export
PUBLIC               = $(EXPORT)/public
DIRS                 = $(PUBLIC)/js $(PUBLIC)/css $(PUBLIC)/api $(EXPORT)/inc

CSS_FILES            = $(wildcard src/css/*.css)
CSS_HASH             := $(shell cat $(CSS_FILES) | md5 | cut -b 1-8)
CSS_MINIFIED         = $(CSS_FILES:src/%.css=export/public/%-$(CSS_HASH).css)

JS_FILES             = $(wildcard src/js/*.js)
JS_HASH              := $(shell cat $(JS_FILES) | md5 | cut -b 1-8)
JS_MINIFIED          = $(JS_FILES:src/%.js=export/public/%-$(JS_HASH).js)

HTML_FILES           = $(wildcard src/*.html src/api/*.html)
HTML_PROCESSED       = $(HTML_FILES:src/%.html=export/public/%.html)

PHP_FILES            = $(wildcard src/inc/*.php)
PHP_PROCESSED        = $(PHP_FILES:src/inc/%.php=export/inc/%.php)

MANIFEST             = src/manifest.json
MANIFEST_PROCESSED   = $(PUBLIC)/manifest.json

OTHER_FILES          = src/favicon.ico src/robots.txt src/img src/sitemap.xml

STAGING_HOST         = staging.uprzejmiedonosze.net
PROD_HOST            = uprzejmiedonosze.net
HOST                 = $(STAGING_HOST)

BRANCH_ENV           = .branch-env
GIT_BRANCH           = $(shell git rev-parse --abbrev-ref HEAD)
LAST_RUN             = $(shell test -s $(BRANCH_ENV) && cat $(BRANCH_ENV) || echo "clean")
TAG_NAME             = $(shell echo prod_$(GIT_BRANCH)_`date +%Y-%m-%d_%H.%M.%S`)

.DEFAULT_GOAL := help

.PHONY: help clean log-from-last-prod
help: ## Displays this help.
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST)  | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s- \033[0m %s\n", $$1, $$2}'

staging: HOST := $(STAGING_HOST)
staging: $(DIRS) export ## Copy files to staging server.
	@echo "==> Copying files and dirs for $@"
	@$(RSYNC) $(RSYNC_FLAGS) $(EXPORT)/* $(HOSTING):/var/www/$(HOST)

prod: HOST := $(PROD_HOST)
prod: clean $(DIRS) export ## Copy files to prod server.
	@echo "==> Copying files and dirs for $@"
	@git tag -a $(TAG_NAME) -m "release na produkcji"
	@git push origin --quiet $(TAG_NAME)
	@$(RSYNC) $(RSYNC_FLAGS) $(EXPORT)/* $(HOSTING):/var/www/$(HOST)
	@make clean

export: $(DIRS) minify ## Exports files for deployment.
	@echo "==> Exporting"
	@test "$(LAST_RUN)" = "clean" -o "$(LAST_RUN)" = "$(GIT_BRANCH)|$(HOST)" || ( echo "Branch or env change detected. Was [$(LAST_RUN)] now is [$(GIT_BRANCH)|$(HOST)]. Clean-up first." && exit 1 )
	@echo "$(GIT_BRANCH)|$(HOST)" > $(BRANCH_ENV)
	@cp -r $(OTHER_FILES) $(PUBLIC)/
	@cp src/api/*twig $(PUBLIC)/api/
	@cp -r lib vendor src/*.php $(HOST)-firebase-adminsdk.json $(EXPORT)/

minify: minify-css minify-js process-html process-php process-manifest ## Minifies CSS and JS, processing PHP, HTML and manifest.json files.
minify-css: $(DIRS) $(CSS_FILES) $(CSS_MINIFIED)
minify-js: $(DIRS) $(JS_FILES) $(JS_MINIFIED)
process-html: $(DIRS) $(HTML_FILES) $(HTML_PROCESSED)
process-php: $(DIRS) $(PHP_FILES) $(PHP_PROCESSED)
process-manifest: $(DIRS) $(MANIFEST) $(MANIFEST_PROCESSED)

clean: ## Removes minified CSS and JS files.
	@echo "==> Cleaning"
	@rm -rf $(EXPORT)
	@rm -f $(BRANCH_ENV)

# Generics
export/public/css/%-$(CSS_HASH).css: src/css/%.css; @echo '==> Minifying $< to $@'
	@if [ "$(HOST)" = "$(PROD_HOST)" ]; then \
		$(YUI_COMPRESSOR) $(YUI_COMPRESSOR_FLAGS) --type css $< > $@ ; \
	else \
		cp $< $@ ; \
	fi;

export/public/js/%-$(JS_HASH).js: src/js/%.js; @echo '==> Minifying $< to $@'
	@if [ "$(HOST)" = "$(PROD_HOST)" ]; then \
		$(YUI_COMPRESSOR) $(YUI_COMPRESSOR_FLAGS) --type js $< > $@ ; \
	else \
		cp $< $@ ; \
	fi;
	$(replace-inline)

export/public/%.html: src/%.html $(CSS_FILES) $(JS_FILES); @echo '==> Preprocessing $<'
	$(lint)
	$(replace)

export/inc/%.php: src/inc/%.php $(CSS_FILES) $(JS_FILES); @echo '==> Preprocessing $<'
	$(lint)
	$(replace)

$(MANIFEST_PROCESSED): $(MANIFEST); @echo '==> Preprocessing $<'
	$(replace)

$(EXPORT)/%: ; @echo "==> Creating $@"
	@mkdir --parents $@

# Firebase

deploy-firebase-staging: ## Deploy Firebase functions for staging env.
	@firebase -P uprzejmiedonosze-1494607701827 deploy --only functions

deploy-firebase-prod: ## Deploy Firebase functions for prod env.
	@firebase -P uprzejmie-donosze deploy --only functions

serve-firebase: ## serve Firebase functions locally
	@firebase serve --only functions

# Utils

define replace
@sed 's/%HOST%/$(HOST)/g' $< > $@
$(replace-inline)
endef

define replace-inline
@sed -i '' 's/%JS_HASH%/$(JS_HASH)/g' $@ 
@sed -i '' 's/%CSS_HASH%/$(CSS_HASH)/g' $@ 
endef

define lint
@! php -l $< | grep -v "No syntax errors detected"
endef

# GIT

log-from-last-prod: ## Show list of commit messages from last prod release till now
	@git log --color --pretty=format:"%cn %ci %s" HEAD...`git show-ref --tags | grep tags/prod_ | tail -n 1 | cut -d" " -f 1`

diff-from-last-prod: ## Show list of commit messages from last prod release till now
	@git diff --histogram --color-words `git show-ref --tags | grep tags/prod_ | tail -n 1 | cut -d" " -f 1`


