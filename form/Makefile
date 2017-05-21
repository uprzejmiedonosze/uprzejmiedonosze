
DIRS=css lib img js inc
FILES=*.html *.php favicon.ico robots.txt
HOST=nieradka.net
RSYNC=rsync

staging:
	$(RSYNC) --verbose -r $(DIRS) $(FILES) $(HOST):/var/www/staging.uprzejmiedonosze.net

prod:
	$(RSYNC) --verbose -r $(DIRS) $(FILES) $(HOST):/var/www/uprzejmiedonosze.net
