# tools
YUI_COMPRESSOR       = java -jar tools/yuicompressor-2.4.8.jar
YUI_COMPRESSOR_FLAGS = --charset utf-8 --line-break 72
RSYNC                = rsync
RSYNC_FLAGS          = --human-readable --recursive --exclude 'vendor/bin/jp.php'
HOSTING              = nieradka.net

# dirs and files 
EXPORT               = export
PUBLIC               = $(EXPORT)/public
DIRS                 = $(PUBLIC)/js $(PUBLIC)/css $(EXPORT)/inc

CSS_FILES            = $(wildcard css/*.css)
CSS_MINIFIED         = $(CSS_FILES:%.css=export/public/%.css)
CSS_HASH             := $(shell cat $(CSS_FILES) | md5 | cut -b 1-8)

JS_FILES             = $(wildcard js/*.js)
JS_MINIFIED          = $(JS_FILES:%.js=export/public/%.js)
JS_HASH              := $(shell cat $(JS_FILES) | md5 | cut -b 1-8)

HTML_FILES           = $(wildcard *.html)
HTML_PROCESSED       = $(HTML_FILES:%.html=export/public/%.html)

PHP_FILES            = $(wildcard inc/*.php)
PHP_PROCESSED        = $(PHP_FILES:inc/%.php=export/inc/%.php)

MANIFEST             = manifest.json
MANIFEST_PROCESSED   = $(PUBLIC)/manifest.json

OTHER_FILES          = favicon.ico robots.txt api

STAGING_HOST         = staging.uprzejmiedonosze.net
PROD_HOST            = uprzejmiedonosze.net
HOST                 = $(STAGING_HOST)

.DEFAULT_GOAL := help

.PHONY: help
help: ## Displays this help.
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST)  | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s- \033[0m %s\n", $$1, $$2}'

staging: HOST := $(STAGING_HOST)
staging: $(DIRS) export ## Copy files to staging server.
	@echo "==> Copying files and dirs for $@"
	@$(RSYNC) $(RSYNC_FLAGS) $(EXPORT)/* $(HOSTING):/var/www/$(HOST)

prod: HOST := $(PROD_HOST)
prod: clean $(DIRS) export ## Copy files to prod server.
	@echo "==> Copying files and dirs for $@"
	@$(RSYNC) $(RSYNC_FLAGS) $(EXPORT)/* $(HOSTING):/var/www/$(HOST)

export: $(DIRS) minify ## Exports files for deployment.
	@echo "==> Exporting"
	@cp -r $(OTHER_FILES) img $(PUBLIC)/
	@cp -r lib vendor *.php uprzejmiedonosze-1494607701827*.json $(EXPORT)/

minify: minify-css minify-js process-html process-php process-manifest ## Minifies CSS and JS, processing PHP, HTML and manifest.json files.
minify-css: $(DIRS) $(CSS_FILES) $(CSS_MINIFIED)
minify-js: $(DIRS) $(JS_FILES) $(JS_MINIFIED)
process-html: $(DIRS) $(HTML_FILES) $(HTML_PROCESSED)
process-php: $(DIRS) $(PHP_FILES) $(PHP_PROCESSED)
process-manifest: $(DIRS) $(MANIFEST) $(MANIFEST_PROCESSED)

clean: ## Removes minified CSS and JS files.
	@echo "==> Cleaning"
	@rm -rf $(CSS_MINIFIED) $(JS_MINIFIED) $(EXPORT)

# Generics

export/public/css/%.css: css/%.css; @echo '==> Minifying $<'
	@if [ "$(HOST)" = "$(PROD_HOST)" ]; then \
		$(YUI_COMPRESSOR) $(YUI_COMPRESSOR_FLAGS) --type css $< > $@ ; \
	else \
		cp $< $@ ; \
	fi;

export/public/js/%.js: js/%.js; @echo '==> Minifying $<'
	@if [ "$(HOST)" = "$(PROD_HOST)" ]; then \
		$(YUI_COMPRESSOR) $(YUI_COMPRESSOR_FLAGS) --type js $< > $@ ; \
	else \
		cp $< $@ ; \
	fi;
	$(replace-inline)

export/public/%.html: %.html; @echo '==> Preprocessing $<'
	$(replace)

export/inc/%.php: inc/%.php; @echo '==> Preprocessing $<'
	$(replace)

$(MANIFEST_PROCESSED): $(MANIFEST); @echo '==> Preprocessing $<'
	$(replace)

$(EXPORT)/%: ; @echo "==> Creating $@"
	@mkdir --parents $@

# Utils

define replace
@sed 's/%HOST%/$(HOST)/g' $< > $@
$(replace-inline)
endef

define replace-inline
@sed -i '' 's/%JS_HASH%/$(JS_HASH)/g' $@ 
@sed -i '' 's/%CSS_HASH%/$(CSS_HASH)/g' $@ 
endef