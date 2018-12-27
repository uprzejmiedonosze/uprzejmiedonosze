<?PHP 

$categories = Array(
	7  => ["Niezastosowanie się do znaków poziomych",
		"Pojazd był zaparkowany w sposób niezgodny z oznaczeniem poziomym.",
		"Jeśli na danym obszarze, miejsca do parkowania są wyznaczone za pomocą znaków poziomych, to kierowców obowiązują właśnie te oznaczenia. Nie ma żadnego znaczenia, czy na chodniku zostało 1,5m, ani sprawdzać czy do przejścia dla pieszych pozostało 8 czy 10 metrów."],
	9  => ["Blokowanie ścieżki rowerowej",
		"Pojazd blokował drogę dla rowerów.",
		'Reguła jest prosta – parkowanie (także "na chwilę") jest niedozwolone.'],
	6  => ["Parkowanie na trawniku/w parku",
		"Pojazd był zaparkowany na trawniku/w parku.",
		'Reguła jest prosta – parkowanie (także "na chwilę") na trawie/parku/zieleni (lub czymś co było kiedyś zielenią) jest niedozwolone.'],
	8  => ["Parkowanie z dala od krawędzi jezdni",
		"Pojazd był zaparkowany z dala od krawędzi jezdni.",
		"Pojazd może wjechać na chodnik wyłącznie przodem, i musi stać przy krawędzi jezdni. Jakiekolwiek ustawienie pojazdu, które wskazywałoby na to, że kierowca jechał chodnikiem aby znaleźć się w tym miejscu jest naruszeniem przepisów."],
	10 => ["Parkowanie za barierkami",
		"Pojazd znajdował poza za barierkami ograniczającymi parkowanie.",
		'Przypadek specyficzny dla "parkowania z dala od krawędzi jezdni", ale bardzo czytelny. Stojąc za słupkami (których celem jest ograniczenie parkowania) pojazd z pewnością nie znajduje się przy krawędzi jezdni.'],
	4  => ["Zastawienie chodnika (mniej niż 1.5m)",
		"Pojazd zastawiał chodnik (mniej niż 1.5m).",
		""],
	2  => ["Mniej niż 15m od przystanku",
		"Pojazd znajdował się mniej niż 15m od przystanku.",
		""],
	3  => ["Mniej niż 10m od skrzyżowania",
		"Pojazd znajdował się mniej niż 10m od skrzyżowania.",
		""],
	5  => ["Mniej niż 10m od przejścia dla pieszych",
		"Pojazd znajdował się mniej niż 10m od przejścia dla pieszych.",
		""],
	// 1  => ["Parkowanie na niezgodne z oznaczeniem", "Pojazd był zaparkowany w sposób niezgodny z oznaczeniem pionowym i poziomym.", ""],
	0 => ["Inne",
		"Inne",
		""]
);

$sm_addresses = Array (
	'szczecin' => 'Referat Wykroczeń \\\\ Straż Miejska Szczecin \\\\ ul Klonowica 1b \\\\ 71-241 Szczecin',
	'warszawa' => 'Referat Oskarżycieli Publicznych \\\\ Straż Miejska m.st. Warszawy \\\\ ul. Młynarska 43/45 \\\\ 01-170 Warszawa',
	'warsaw' => 'Referat Oskarżycieli Publicznych \\\\ Straż Miejska m.st. Warszawy \\\\ ul. Młynarska 43/45 \\\\ 01-170 Warszawa'
);

$categoriesMatrix = Array( 'a', 'b');

$statuses = Array (
    // KEY                  0 desc               1 action             2 icon     3 class     4 color (not used)
    'draft'             => ['Draft',             'Dalej',             'edit',    null,       ''],
    'ready'             => ['Gotowe',            'Potwierdź',         'shop',    null,       ''],
    'confirmed'         => ['Nowe',              'Nowe',              'carat-u', 'active',   ''],
	'confirmed-waiting' => ['Oczekuje',          'Wysłane',           'clock',   'active',   ''],
	'confirmed-sm'      => ['Po wizycie',        'Po wizycie w SM',   'user',    'active',   ''],
    'confirmed-ignored' => ['Zignorowane',       'Zignorowane',       'delete',  'active',   ''],
    'confirmed-fined'   => ['Wystawiony mandat', 'Wystawiony mandat', 'check',   'active',   ''],
    'archived'          => ['W archiwum',        'Archiwizuj',        'cloud',   'archived', '']
);

?>