all-stop: init-db
	@nosetests --verbose --stop --nocapture --nologcapture --pdb --detailed-errors UDStatic_test.py UDNew_test.py UDMyApps_test.py

all-cont: init-db
	@nosetests --verbose --nocapture --nologcapture --pdb --detailed-errors UDStatic_test.py UDNew_test.py UDMyApps_test.py

failed: init-db
	@nosetests --verbose --stop --nocapture --nologcapture --pdb --detailed-errors --failed UDStatic_test.py UDNew_test.py UDMyApps_test.py

init-db:
	ssh nieradka.net "cd /var/www/staging.uprzejmiedonosze.net/db && cp store.sqlite-registered store.sqlite"
