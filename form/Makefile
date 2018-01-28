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

# target: staging - Copy files to staging server
staging: HOST := $(STAGING_HOST)
staging: $(DIRS) export; @echo "==> Copying files and dirs for $@"
	@$(RSYNC) $(RSYNC_FLAGS) $(EXPORT)/* $(HOSTING):/var/www/$(HOST)

# target: prod - Copy files to prod server
prod: HOST := $(PROD_HOST)
prod: clean $(DIRS) export; @echo "==> Copying files and dirs for $@"
	@$(RSYNC) $(RSYNC_FLAGS) $(EXPORT)/* $(HOSTING):/var/www/$(HOST)

# target: export - Exports files for deployment
export: $(DIRS) minify; @echo "==> Exporting"
	@cp -r $(OTHER_FILES) img $(PUBLIC)/
	@cp -r lib vendor *.php uprzejmiedonosze-1494607701827*.json $(EXPORT)/

# target: minify - Minifies CSS and JS.
minify: minify-css minify-js process-html process-php

# target: minify-css - Minifies CSS.
minify-css: $(DIRS) $(CSS_FILES) $(CSS_MINIFIED)

# target: minify-js - Minifies JS.
minify-js: $(DIRS) $(JS_FILES) $(JS_MINIFIED)

# target: process-html - Preprocessing HTML.
process-html: $(DIRS) $(HTML_FILES) $(HTML_PROCESSED)

# target: process-php - Preprocessing PHP.
process-php: $(DIRS) $(PHP_FILES) $(PHP_PROCESSED)

# target: process-manifest - Preprocessing manifest.json
process-manifest: $(DIRS) $(MANIFEST) $(MANIFEST_PROCESSED)

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
	@sed -i '' 's/%JS_HASH%/$(JS_HASH)/g' $@ 
	@sed -i '' 's/%CSS_HASH%/$(CSS_HASH)/g' $@ 


export/public/%.html: %.html; @echo '==> Preprocessing $<'
	@sed 's/%HOST%/$(HOST)/g' $< | \
	sed 's/%JS_HASH%/$(JS_HASH)/g' | \
	sed 's/%CSS_HASH%/$(CSS_HASH)/g' > $@ 

export/inc/%.php: inc/%.php; @echo '==> Preprocessing $<'
	@sed 's/%HOST%/$(HOST)/g' $< | \
	sed 's/%JS_HASH%/$(JS_HASH)/g' | \
	sed 's/%CSS_HASH%/$(CSS_HASH)/g' > $@ 

$(MANIFEST_PROCESSED): $(MANIFEST); @echo '==> Preprocessing $<'
	@sed 's/%JS_HASH%/$(JS_HASH)/g' | \
	sed 's/%CSS_HASH%/$(CSS_HASH)/g' > $@

$(EXPORT)/%: ; @echo "==> Creating $@"
	@mkdir --parents $@

# target: clean - Removes minified CSS and JS files.
clean:; @echo "==> Cleaning"
	@rm -rf $(CSS_MINIFIED) $(JS_MINIFIED) $(EXPORT)

# target: help - Displays help.
help:; @egrep "^# target:" Makefile
