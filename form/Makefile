# tools
YUI_COMPRESSOR = java -jar tools/yuicompressor-2.4.8.jar
YUI_COMPRESSOR_FLAGS = --charset utf-8 --line-break 72
RSYNC = rsync
RSYNC_FLAGS = --human-readable --recursive
HOSTING=nieradka.net

# dirs and files 
EXPORT = export
PUBLIC = $(EXPORT)/public
DIRS = $(PUBLIC)/js $(PUBLIC)/css $(EXPORT)/inc

CSS_FILES = $(filter-out %-min.css,$(wildcard css/*.css))
CSS_MINIFIED = $(CSS_FILES:.css=-min.css)

JS_FILES = $(filter-out %-min.js,$(wildcard js/*.js))
JS_MINIFIED = $(JS_FILES:.js=-min.js)

HTML_FILES = $(filter-out export/public/%.html,$(wildcard *.html))
HTML_PROCESSED = $(HTML_FILES:%.html=export/public/%.html)

PHP_FILES = $(filter-out export/inc/%.php,$(wildcard inc/*.php))
PHP_PROCESSED = $(PHP_FILES:inc/%.php=export/inc/%.php)

OTHER_FILES = favicon.ico robots.txt manifest.json sw.js

HOST := staging.uprzejmiedonosze.net

# target: staging - Copy files to staging server
staging: HOST := staging.uprzejmiedonosze.net
staging: init export; @echo "==> Copying files and dirs for $@"
	@$(RSYNC) $(RSYNC_FLAGS) $(EXPORT)/* $(HOSTING):/var/www/$(HOST)

# target: prod - Copy files to prod server
#prod: HOST := uprzejmiedonosze.net
#prod: init export; @echo "==> Copying files and dirs for $@"
#	@$(RSYNC) $(RSYNC_FLAGS) $(EXPORT)/* $(HOSTING):/var/www/$(HOST)

init: $(DIRS); @echo "==> Creating dirs"

$(PUBLIC)/js:
	@mkdir --parents $@
$(PUBLIC)/css:
	@mkdir --parents $@
$(EXPORT)/inc:
	@mkdir --parents $@

# target: export - Exports files for deployment
export: init minify; @echo "==> Exporting"
	@cp -r $(OTHER_FILES) img $(PUBLIC)/
	@cp -r lib vendor *.php $(EXPORT)/

# target: minify - Minifies CSS and JS.
minify: minify-css minify-js process-html process-php

# target: minify-css - Minifies CSS.
minify-css: init $(CSS_FILES) $(CSS_MINIFIED)

# target: minify-js - Minifies JS.
minify-js: init $(JS_FILES) $(JS_MINIFIED)

# target: process-html - Preprocessing HTML.
process-html: init $(HTML_FILES) $(HTML_PROCESSED)

# target: process-php - Preprocessing PHP.
process-php: init $(PHP_FILES) $(PHP_PROCESSED)

%-min.css: %.css; @echo '==> Minifying $<'
	@$(YUI_COMPRESSOR) $(YUI_COMPRESSOR_FLAGS) --type css $< >$(PUBLIC)/$@

%-min.js: %.js; @echo '==> Minifying $<'
	@$(YUI_COMPRESSOR) $(YUI_COMPRESSOR_FLAGS) --type js $< >$(PUBLIC)/$@

export/public/%.html: %.html; @echo '==> Preprocessing $<'
	@sed 's/%HOST%/$(HOST)/g' $< > $@

export/inc/%.php: inc/%.php; @echo '==> Preprocessing $< » $@'
	@sed 's/%HOST%/$(HOST)/g' $< > $@

# target: clean - Removes minified CSS and JS files.
clean:; @echo "==> Cleaning"
	@rm -rf $(CSS_MINIFIED) $(JS_MINIFIED) $(EXPORT)

# target: help - Displays help.
help:; @egrep "^# target:" Makefile