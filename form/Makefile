DIRS=css lib img js inc vendor
DIRS2=lib img inc vendor api
FILES=*.html favicon.ico robots.txt manifest.json sw.js
HOST=nieradka.net
RSYNC=rsync
EXPORT=export
PUBLIC=$(EXPORT)/public

.PHONY: init

staging: init minify copy
	$(RSYNC) --verbose -r $(EXPORT) $(HOST):/var/www/staging.uprzejmiedonosze.net

prod: init minify
	$(RSYNC) --verbose -r $(DIRS) $(FILES) $(HOST):/var/www/uprzejmiedonosze.net

init:
	mkdir --parents $(PUBLIC)/js
	mkdir --parents $(PUBLIC)/css

export: init
	cp -r $(FILES) img $(PUBLIC)/
	cp -r lib inc vendor *.php $(EXPORT)/

# Patterns matching CSS files that should be minified. Files with a -min.css
# suffix will be ignored.
CSS_FILES = $(filter-out %-min.css,$(wildcard \
	css/*.css \
))

# Patterns matching JS files that should be minified. Files with a -min.js
# suffix will be ignored.
JS_FILES = $(filter-out %-min.js,$(wildcard \
	js/*.js \
))

# Command to run to execute the YUI Compressor.
YUI_COMPRESSOR = java -jar tools/yuicompressor-2.4.8.jar

# Flags to pass to the YUI Compressor for both CSS and JS.
YUI_COMPRESSOR_FLAGS = --charset utf-8 --verbose

CSS_MINIFIED = $(CSS_FILES:.css=-min.css)
JS_MINIFIED = $(JS_FILES:.js=-min.js)

# target: minify - Minifies CSS and JS.
minify: minify-css minify-js
		
# target: minify-css - Minifies CSS.
minify-css: $(CSS_FILES) $(CSS_MINIFIED) init
	mv $(CSS_MINIFIED) $(PUBLIC)/css

# target: minify-js - Minifies JS.
minify-js: $(JS_FILES) $(JS_MINIFIED) init
	mv $(JS_MINIFIED) $(PUBLIC)/js

%-min.css: %.css
	@echo '==> Minifying $<'
	$(YUI_COMPRESSOR) $(YUI_COMPRESSOR_FLAGS) --type css $< >$@
	@echo

%-min.js: %.js
	@echo '==> Minifying $<'
	$(YUI_COMPRESSOR) $(YUI_COMPRESSOR_FLAGS) --type js $< >$@
	@echo

# target: clean - Removes minified CSS and JS files.
clean:
	rm -rf $(CSS_MINIFIED) $(JS_MINIFIED) $(EXPORT)

# target: help - Displays help.
help:
	@egrep "^# target:" Makefile
