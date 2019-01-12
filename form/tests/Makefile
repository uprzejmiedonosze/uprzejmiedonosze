selenium-start:
	@if ! pgrep -nf "selenium-standalone" > /dev/null; then \
		(selenium-standalone start &); \
		sleep 2; \
	fi

selenium-stop:
	@pkill -f "selenium-standalone"
	@pkill -f "gecko"
