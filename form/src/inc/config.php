<?PHP 

$categories = Array(
	4  => "Zastawienie chodnika (mniej niż 1.5m)",
	2  => "Mniej niż 15m od przystanku",
	3  => "Mniej niż 10m od skrzyżowania",
	9  => "Blokowanie ścieżki rowerowej	",
	5  => "Mniej niż 10m od przejścia dla pieszych",
	6  => "Parkowanie na trawniku/w parku",
	10 => "Parkowanie za barierkami",
	8  => "Parkowanie z dala od krawędzi jezdni",
	//7  => "Parkowanie w na chodniku / niszczenie chodnika",
	1  => "Parkowanie na niezgodne z oznaczeniem",
	0 => "Inne"
);

$categories_txt = Array (
    4  => "Pojazd zastawiał chodnik (mniej niż 1.5m).",
	2  => "Pojazd znajdował się mniej niż 15m od przystanku.",
    3  => "Pojazd znajdował się mniej niż 10m od skrzyżowania.",
    9  => "Pojazd blokował ścieżkę rowerową.",
    5  => "Pojazd znajdował się mniej niż 10m od przejścia dla pieszych.",
    6  => "Pojazd był zaparkowany na trawniku/w parku.",
    10 => "Pojazd znajdował poza za barierkami ograniczającymi parkowanie.",
    8  => "Pojazd był zaparkowany z dala od krawędzi jezdni.",
	7  => "Pojazd był zaparkowany w sposób niezgodny z oznaczeniem pionowym i poziomym.", // (only for history)
	1  => "Pojazd był zaparkowany w sposób niezgodny z oznaczeniem pionowym i poziomym.",
    0  => ""
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