<?PHP 

$categories = Array(
	7  => ["Niezastosowanie się do znaków",
		"Pojazd był zaparkowany w sposób niezgodny z oznaczeniem poziomym lub pionowym.",
		"<p>Jeśli na danym obszarze, miejsca do parkowania są wyznaczone za pomocą znaków poziomych lub pionowych, to kierowców obowiązują właśnie te znaki. Nie ma żadnego znaczenia, czy na chodniku zostało 1,5m albo czyczy do przejścia dla pieszych pozostało 8 czy 10 metrów.</p> <p><i>(PoRD Art. 46. 4. Kierujący pojazdem jest obowiązany stosować sposób zatrzymania lub postoju wskazany znakami drogowymi.)</i></p>"],
	8  => ["Parkowanie z dala od krawędzi jezdni",
		"Pojazd był zaparkowany z dala od krawędzi jezdni.",
		"<p>Pojazd może wjechać na chodnik wyłącznie przodem, i musi stać przy krawędzi jezdni. Jakiekolwiek ustawienie pojazdu, które wskazywałoby na to, że kierowca jechał chodnikiem aby znaleźć się w tym miejscu jest naruszeniem przepisów.</p> <p><i>(PoRD Art. 47. 2. Dopuszcza się, przy zachowaniu warunków określonych w ust. 1 pkt 2 (szerokość chodnika pozostawionego dla pieszych jest taka, że nie utrudni im ruchu i jest nie mniejsza niż 1,5m), zatrzymanie lub postój na chodniku przy krawędzi jezdni całego samochodu osobowego, motocykla, motoroweru lub roweru.)</i></p>"],
	10 => ["Parkowanie za barierkami",
		"Pojazd znajdował poza za barierkami ograniczającymi parkowanie.",
		"<p>Przypadek specyficzny dla 'parkowania z dala od krawędzi jezdni', ale bardzo czytelny. Stojąc za słupkami (których celem jest ograniczenie parkowania) pojazd z pewnością nie znajduje się przy krawędzi jezdni. Oczywiście ograniczenie za które wjechał pojazd wcale nie musi być barierką czy rzędem słupków. Dla trawnika, żywopłotu czy płotka działają te same zasady. </p> <p><i>(PoRD Art. 47. 2. cytowany powyżej.)</i></p>"],
	4  => ["Zastawienie chodnika (mniej niż 1.5m)",
		"Pojazd zastawiał chodnik (mniej niż 1.5m).",
		"<p>Sprawdź najpierw, czy nie ma oznaczeń pionowych ani poziomych a samochód stoi przy krawędzi jezdni. Dopiero wtedy, jeśli szerokość chodnika pozostawionego dla pieszych jest taka, że pojazd utrudnia im ruch (i jest to przynajmniej 1,5m) ma zastosowanie ten przepis.</p> <p><i>(PoRD Art. 47. 1. Dopuszcza się zatrzymanie lub postój na chodniku kołami jednego boku lub przedniej osi pojazdu samochodowego o dopuszczalnej masie całkowitej nie przekraczającej 2,5 t, pod warunkiem, że na danym odcinku jezdni nie obowiązuje zakaz zatrzymania lub postoju, szerokość chodnika pozostawionego dla pieszych jest taka, że nie utrudni im ruchu i jest nie mniejsza niż 1,5m [...])</i></p>"],
	2  => ["Mniej niż 15m od przystanku",
		"Pojazd znajdował się mniej niż 15m od przystanku.",
		"<p>Parkowanie/zatrzymywanie pojazdu na przystanku (zarówno na jezdni jak i na chodniku) jest niedozwolone.</p> <p><i>(PoRD Art. 49. 1.9. Zabrania się zatrzymania pojazdu w odległości mniejszej niż 15 m od słupka lub tablicy oznaczającej przystanek, a na przystanku z zatoką - na całej jej długości.)</i></p>"],
	3  => ["Mniej niż 10m od skrzyżowania",
		"Pojazd znajdował się mniej niż 10m od skrzyżowania.",
		"<p>Parkowanie/zatrzymywanie pojazdu przed skrzyżowaniem jest niedozwolone.</p> <p><i>(PoRD Art. 49. 1.1. Zabrania się zatrzymania pojazdu na przejeździe kolejowym, na przejeździe tramwajowym, na skrzyżowaniu oraz w odległości mniejszej niż 10 m od przejazdu lub skrzyżowania.)</i></p>"],
	5  => ["Mniej niż 10m od przejścia dla pieszych",
		"Pojazd znajdował się mniej niż 10m od przejścia dla pieszych.",
		"<p>Parkowanie/zatrzymywanie pojazdu przed przejściem dla pieszych jest niedozwolone.</p> <p><i>(PoRD Art. 49. 1.2. Zabrania się zatrzymania pojazdu na przejściu dla pieszych, na przejeździe dla rowerzystów oraz w odległości mniejszej niż 10 m przed tym przejściem lub przejazdem; na drodze dwukierunkowej o dwóch pasach ruchu zakaz ten obowiązuje także za tym przejściem lub przejazdem.)</i></p>"],
	9  => ["Blokowanie ścieżki rowerowej",
		"Pojazd blokował drogę dla rowerów.",
		"<p>Przepisy są tutaj jednoznacznia. Na ścieżce rowerowej nie wolno ani parkować, ani nawet się zatrzymywać 'na chwilę'.</p> <p><i>(PoRD Art. 49. 1.11. Zabrania się zatrzymania pojazdu na drodze (ścieżce) dla rowerów.)</i></p>"],
	12 => ["Parkowanie w strefie zamieszkania w miejscu niewyznaczonym",
		"Pojazd był zaparkowany w strefie zamieszkania poza miejscem do tego wyznaczonym.",
		"<p>W strefie zamieszkania obowiązuje zakaz parkowania poza miejscami do tego wyznaczonymi. Nie ma znaczenia pozostawione 1,5m, odległość od pasów czy skrzyżowania.</p> <p><i>(PoRD Art. 49. 2.4. Zabrania się zatrzymania pojazdu w strefie zamieszkania w innym miejscu niż wyznaczone w tym celu.)</i></p>"],
	6  => ["Parkowanie na trawniku/w parku",
		"Pojazd był zaparkowany na trawniku/w parku.",
		"<p>Reguła jest prosta – parkowanie (także 'na chwilę') na trawie/parku/zieleni (lub czymś co było kiedyś zielenią) jest niedozwolone. Uważaj jednak na tę kategorię. Wymaga ona wykazania, że widoczna na zdjęciu sytuacja przyczyniła się do 'zniszczenia'. Jeśli więc masz ujęcie na którym widać koleiny za autem – śmiało. W przeciwnym wypadku sprawdź, czy nie pasuje tutaj inna kategoria.</p> <p><i>(KW Art. 144. 1. Kto na terenach przeznaczonych do użytku publicznego niszczy lub uszkadza roślinność [...] podlega karze grzywny do 1.000 złotych albo karze nagany.)</i></p>"],
	1  => [null, "Pojazd był zaparkowany w sposób niezgodny z oznaczeniem pionowym i poziomym.", null],
	11 => ["Pojazd > 2.5t DMC na chodniku",
		"Pojazd o dopuszczalnej masie całkowitej przekraczającej 2,5 tony znajdował się na chodniku.",
		"<p>Na koniec najmniej znane ograniczenie. Na chodniku (wszystko jedno, czy są na nim wyznaczone miejsca parkingowe znakami poziomymi lub pionowymi, czy nie) mogą parkować wyłącznie pojazdy o dopuszczalnej masie całkowitej nie przekraczającej 2,5 tony. Dokładnie tak. To, że pojazd można prowadzić posiadając prawo jazdy kategorii B wcale nie oznacza, że można go postawić na chodniku. Ujmując to dosadniej, kierowcy takich samochodów nie mogą choćby jednym kołem wjechać na chodnik.</p> <p>Jakich samochodów to dotyczy? Automatycznie wszystkich ciężarówek, samochodów dostawczych itp. Ale także zaskakująco dużej ilości samochodów osobowych. Np. Audi A8, SQ5, Q7; BMW X5, X6, 7; Ford Edge, Ford S-MAX (większość wersji); Hyundai Santa Fe; Jeep Grand Cheeroke; Kia Sorento; Mazda CX-9; Mercedes GL, GLC (niektóre wersje), M; Mitsubishi Pajero; Nissan Navara; Land Rover; Range Rover (prócz najprostszej wersji modelu Discovery Sport); Peugeot 807; Porsche Cayenne; Renault Espace (niektóre wersje); Skoda Kodiaq (niektóre wersje); Toyota Hilux, Land Cruiser; Volvo XC60 (niektóre wersje), XC90; Volkswagen Amarok, Touareg.</p> <p><i>(PoRD Art. 47. 1. Dopuszcza się zatrzymanie lub postój na chodniku kołami jednego boku lub przedniej osi pojazdu samochodowego o dopuszczalnej masie całkowitej nie przekraczającej 2,5 t; PoRD Art. 47. 2. Dopuszcza się, przy zachowaniu warunków określonych w ust. 1 pkt 2, zatrzymanie lub postój na chodniku przy krawędzi jezdni całego samochodu osobowego, motocykla, motoroweru lub roweru. Inny pojazd o dopuszczalnej masie całkowitej nie przekraczającej 2,5 t może być w całości umieszczony na chodniku tylko w miejscu wyznaczonym odpowiednimi znakami drogowymi.)</i></p>"],
	0 => ["<i>Pozostałe przypadki (koniecznie dodaj komentarz)</i>",
		"",
		"Wyobraźnia kierowców nie zna granic. Jeśli wybierzesz tę katogorię, koniecznie opisz szczegóły w komentarzu do zgłoszenia."]
);

$sm_addresses = Array (
	'szczecin' => ['Referat Wykroczeń \\\\ Straż Miejska Szczecin \\\\ ul Klonowica 1b \\\\ 71-241 Szczecin',
		'sm@um.szczecin.pl'],
	'warszawa' => ['Referat Oskarżycieli Publicznych \\\\ Straż Miejska m.st. Warszawy \\\\ ul. Młynarska 43/45 \\\\ 01-170 Warszawa',
		'ot1@strazmiejska.waw.pl'],
	'warsaw' => ['Referat Oskarżycieli Publicznych \\\\ Straż Miejska m.st. Warszawy \\\\ ul. Młynarska 43/45 \\\\ 01-170 Warszawa',
		'ot1@strazmiejska.waw.pl']
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
