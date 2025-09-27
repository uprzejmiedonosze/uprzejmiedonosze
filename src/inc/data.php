<?php 
declare(strict_types=1);
namespace generator;

$TOPICS = [
    "1" => [
        "title" => "Nielegalne parkowanie jest tańsze, niż legalne",
        "desc" => <<<EOD
Aktualna wykładnia przepisów o strefach płatnego parkowania powoduje zachętę ekonomiczną do łamania przepisów. Sytuacja, w której parkowanie nielegalne jest tańsze niż legalne:

   - jest niesprawiedliwa wobec uczciwie parkujących kierowców,
   - skutkuje niszczeniem infrastruktury i zieleni,
   - wymusza na samorządach kreowanie polityki parkingowej za pomocą słupków,
   - generuje olbrzymią presję na straże miejskie,
   - wymusza konieczność patrolowania tego samego terenu przez dwie niezależne służby (kontrolerów strefy płatnego parkowania oraz strażników miejskich).

Dlaczego kwota mandatu nie rośnie z czasem? Ponieważ karana jest czynność zatrzymania, a nie sam postój. Oznacza to, że np. tygodniowy postój na zakazie parkowania nadal grozi grzywną w wysokości 100 zł.

Warto zaznaczyć, że dotlikwa opłata dodatkowa w strefie płatnego parkowania zostanie z całą pewnością nałożona na właściciela auta. Natomiast szanse na mandat dla sprawcy wykroczenia są iluzoryczne.

Należy zauważyć, że zgodnie z art. 13 ustawy o drogach publicznych, w razie nieuiszczenia opłaty za parkowanie w strefie płatnego parkowania, obowiązek zapłaty opłaty dodatkowej co do zasady obciąża właściciela pojazdu. Natomiast obowiązujący w przypadku łamania przepisów o ruchu drogowym kodeks postępowania w sprawach o wykroczenia, wymaga ustalenia sprawcy wykroczenia. Proces ustalania sprawcy istotnie wydłuża procedurę mandatową oraz daje wiele możliwości uchylenia się od przyjęcia mandatu, np. poprzez nieodebranie listu poleconego (kpw. nie zawiera tzw. „fikcji doręczenia”) lub wskazanie obcokrajowca. W obu wypadkach procedura jest umarzana.

Strefy Płatnego Parkowania w Polsce zaczęły powstawać w 1994 roku na bazie rozporządzenia rady ministrów. Już wtedy definiowało możliwość pobierania opłaty w strefie za „parkowanie pojazdów w wyznaczonym miejscu”. Treść tego rozporządzenia została w 2003 roku przeniesiona do ustawy o drogach publicznych. Przez następne 20 lat gminy pobierały opłaty w całym obszarze strefy, a wojewódzkie sądy administracyjne twierdziły, że „jeżeli pojazd zostanie zaparkowany na drodze publicznej, ale w miejscu nieprzeznaczonym do parkowania, to korzystający nadal ma obowiązek uiszczenia opłaty za parkowanie”. Sytuację zmieniła uchwała Naczelnego Sądu Administracyjnego z 9 października 2017 r., która jednoznacznie wskazała, że opłatę w strefie można pobierać wyłącznie na miejscach wyznaczonych znakiem pionowym i poziomym.
EOD,
        "topics" => [
            "Przepisy tworzą zachęty ekonomiczne do łamania prawa.",
            "Nieuczciwy kierowca za tydzień parkowania na przystanku być może zapłaci 100 zł mandatu, uczciwy kierowca musi zapłacić wielokrotnie więcej.",
            "Efektem tego jest niszczenie zieleni i infrastruktury, słupkoza oraz olbrzymia presja na straże miejskie."
        ],
        "law" => <<<EOD
Postuluję czytelne i sprawiedliwe rozwiązanie: stoisz na wyznaczonym miejscu – płacisz za postój w strefie. Stoisz w strefie poza miejscem wyznaczonym – płacisz opłatę dodatkową zgodnie z regulaminem. Należy zauważyć, że kontroler strefy nie musi podejmować decyzji o tym, czy pojazd stoi zgodnie z przepisami czy nie. Musi jedynie ustalić, czy znajduje się on na wyznaczonym miejscu, czy poza nim (co już obecnie należy do jego obowiązków).

Oczywiście w sytuacji, w której kierowca poza postojem na miejscu niewyznaczonym np. stwarza zagrożenie w ruchu, naraża się inne rodzaje odpowiedzialności, np. usunięcie pojazdu z drogi na koszt właściciela.

W praktyce, proponuję wykreślenie z ustawy o drogach publicznych zwrotu „w wyznaczonym miejscu” w art. 13b u. 1 i 1a. Dodatkowo proponuję dopisanie w tej samej ustawie dodatkowego celu ustalania stref (art. 13b u. 2): „przeciwdziałaniu nielegalnemu parkowaniu”. Konieczne jest także zmiana art. 13f u. 1 tej ustawy z „Za nieuiszczenie opłat…” na „Za nieuiszczenie opłat lub zaparkowanie poza miejscem wyznaczonym…”.
EOD
    ],

    "2" => [
        "title" => "Przepisy pozwalające na parkowanie na chodniku",
        "desc" => <<<EOD
Przepisy umożliwiające parkowanie na chodniku całym pojazdem silnikowym zostały wprowadzone rozporządzeniem z 1967 r. a następnie przeniesione do przepisów ruchu drogowego w 1982 r. Ta historyczna zaszłość nie przystaje do obecnej rzeczywistości. 

Ten anachroniczny przepis utrwala u użytkowników dróg mylne przekonanie o możliwości parkowania w każdym miejscu, pod warunkiem pozostawienia „1,5 metra”. Wymusza także na zarządcach dróg ustawienia lasu znaków regulujących sposób parkowania, nawet jeśli miejsca postojowe są czytelnie wydzielone z drogi dla pieszych.

Przepisy sprzed półwiecza nie przystają też do obowiązujących przepisów techniczno-budowlanych, które definiują „Szerokość chodnika powinna być nie mniejsza niż 1,80 m)”.
EOD,
        "topics" => [
            "szkodliwy mit o „pozostawieniu półtora metra” jako jedynym warunku prawidłowego parkowania",
            "lasy znaków i słupków próbujących regulować chaos parkingowy",
            "nieczytelny i niespójny stan prawny regulujący społecznie ważnie obszar"
        ],
        "law" => <<<EOD
Postuluję możliwość postoju pojazdu w całości na chodniku wyłącznie w miejscach przeznaczonych (infrastrukturą lub znakami).

Chcę odwrócić obecną sytuację – samochód stojący wszystkimi kołami na drodze dla pieszych nie powinien być standardem, a budzić zdziwienie.

Wymagane jest napisanie od nowa art. 47 przepisów o ruchu drogowym, z uwzględnieniem definicji części drogi dla pieszych oraz nowym, bardziej rygorystycznym podejściem do parkowania całym pojazdem na drodze dla pieszych.
EOD
    ],
    "3" => [
        "title" => "Niskie mandaty za nielegalne parkowanie",
        "desc" => <<<EOD
Obecnie obowiązujące grzywny za wykroczenia związane z parkowaniem nie zostały zmienione od 22 lat. Mimo, że istotne części tzw. taryfikatora zostały w ostatnich latach urealnione. Zmiany te ominęły jednak pozycje związane z zatrzymaniem i postojem pojazdów. Sekcja K (zatrzymanie i postój) w aktualnie obowiązującym taryfikatorze ma identyczne stawki co sekcja tym samym tytule z 24 listopada 2003 roku.

Brak waloryzacji grzywien nakładanych w drodze mandatów karnych za wykroczenia związane z postojem pojazdów spowodował faktyczną depenalizację tych wykroczeń.

Prowadzi to do szeregu absurdów, np. jazda wzdłuż chodnika podlega karze grzywny w wysokości 1 500 złotych. Natomiast parkowanie z dala od krawędzi jezdni, które wymaga jazdy wzdłuż chodnika, nadal karane jest kwotą 100 zł. Mandat za nieprawidłowe parkowanie jest niższy od opłaty dodatkowej za złamanie regulaminu parkowania w strefie płatnego parkowania. Mandat za nieprawidłowe parkowanie również jest wielokrotnie niższy niż kara finansowa za jazdę bez biletu w środku komunikacji zbiorowej (np. 550 w Katowicach oraz 400 zł w Szczecinie).
EOD,
        "topics" => [
            "pensja minimalna wzrosła w tym czasie z 800 zł do 4 666 zł",
            "kara za przejazd autobusem „na gapę” to nawet 550 zł, za nielegalne parkowanie 100 zł",
            "mandat za rzucenie papierka na chodnik to 500 zł, porzucenie na nim auta to mandat 100 zł",
            "przestarzały taryfikator powoduje faktyczną depenalizację patoparkowania"
        ],
        "law" => <<<EOD
Proponuję waloryzację wszystkich pozycji w sekcji K („Zatrzymanie i postój”) o 300% – czyli wyraźnie poniżej wskaźnika wzrostu pensji minimalnej. Równocześnie postuluję uzupełnienie art. 38 § 2 Kodeksu wykroczeń („Recydywa wielokrotna”) o art. 97 („Naruszenie przepisów o bezpieczeństwie lub porządku w ruchu drogowym”).

W efekcie, sankcja dla kierowcy, który dokonał wykroczenia zw. z parkowaniem jeden raz, będzie odczuwalna, ale nie bardzo uciążliwa. Natomiast uciążliwość tej sankcji wzrośnie znacznie wobec recydywistów.
EOD
    ],
    "4" => [
        "title" => "Przyzwolenie na niszczenie zieleni przez kierowców",
        "desc" => <<<EOD
Przepisy o ruchu drogowym nie zawierają słów „zieleń” lub „trawa”. Parkowanie na trawnikach, zieleni urządzonej lub w parku nie są zabronione. Straż miejska może korzystać jedynie z kodeksu wykroczeń, który mówi jednak o „niszczeniu lub uszkadzaniu roślinności”. Zdecydowana większość straży miejskich oczekuje dowodu, że konkretna czynność parkowania doprowadziła do uszkodzenia roślinności.

To jednak tylko część problemu. Konieczność korzystania z kodeksu wykroczeń uniemożliwia strażnikom miejskim założenie blokady na tak zaparkowany pojazd ani nałożenia punktów karnych. Co więcej, nie ma wtedy zastosowania art. 96/3 kodeksu wykroczeń, który umożliwia ukarania właściciela pojazdu za niewskazanie sprawcy wykroczenia.
EOD,
        "topics" => [   
            "brak możliwości założenia blokady na auto sprawcy",
            "brak możliwości przyznania punktów karnych",
            "brak możliwości odholowania”auta",
            "wymaga nadzwyczajnych środków i determinacji strażników miejskich, żeby nałożyć jakąkolwiek karę dla sprawców"
        ],
        "law" => <<<EOD
Najprostsze rozwiązanie, polegające na wprowadzeniu zakazu parkowania na „zieleni lub terenie przeznaczonym na zieleń” spowodowałoby w mojej ocenie masowe „utwardzanie” istniejących terenów zielonych, które do tej pory służyły jako legalne lub nielegalne miejsca postojowe.

Dlatego proponujemy jedynie Uzupełnienia art. 45 ust. 1 przepisów o ruchu drogowym o pozycję 11) „niszczenia lub uszkadzania roślinności” oraz uzupełnienie taryfikatora mandatów o stosowną pozycję powielającą kwotę wskazaną w art. 144 kodeksu wykroczeń (tj. 1000 zł).

Rozwiązuje to co prawda jedynie część problemu, ale daje więcej narzędzi strażom miejskim i nie wywołuje niezamierzonych efektów ubocznych.
EOD
    ],

    "5" => [
        "title" => "Unikanie mandatów przez nieuczciwych kierowców (np. przez wskazywanie obcokrajowców)",
        "desc" => <<<EOD
W myśl kodeksu wykroczeń oraz kodeksu postępowania w sprawach o wykroczenia, sankcje nakłada się na sprawcę wykroczenia. Wymóg ten, w połączeniu z powszechnością tych wykroczeń istotnie obniża nieuchronność kary. W kodeksie postępowania w sprawach o wykroczenia nie występuje tzw. fikcja doręczenia przesyłki.

Oznacza to, że straż miejska umorzy postępowanie, jeśli właściciel pojazdu nie odbierze listu poleconego z wezwaniem do wskazania sprawcy. Także wskazanie jako sprawcy obcokrajowca lub nieistniejącej osoby także zamyka postępowanie.

Problem ten jest widoczny w statystykach publikowanych przez Centrum Automatycznego Nadzoru nad Ruchem Drogowym. Wystawianie mandatów za przekroczenie prędkości odbywa się zgodnie z tym samym kodeksem co mandatów za nieprawidłowe parkowanie.

Przez cały 2024 r. urządzenia do automatycznej kontroli zarejestrowały w sumie 1 162 968 wykroczeń. W tym samym czasie inspektorzy nałożyli 568 627 mandatów. To oznacza, że niecałe 49 proc. sprawców przekroczenia prędkości lub przejazdu na czerwonym świetle zostało ukaranych.
EOD,
        "topics" => [
            "mniej niż połowa postępowań mandatowych kończy się mandatem",
            "recydywiści masowo nie płącą mandatów",
            "patent „na obcokrajowca” jest powszechnie stosowanym sposobem na uniknięcie mandatu i punktów karnych"
        
        ],
        "law" => <<<EOD
Rozwiązaniem problemu jest domyślne karanie właściciela pojazdu. Podobnie jak ma to miejsce w przypadku nakładania opłat dodatkowych w strefie płatnego parkowania. Oczywiście właściciel pojazdu powinien móc skutecznie zwolnić się z obowiązku uiszczenia mandatu pod warunkiem doręczenia organowi dowodu potwierdzającego korzystanie z pojazdu przez osobę trzecią w postaci pisemnego oświadczenia osoby kierującej pojazdem, zawierającego jej dane.
EOD
    ],

    "6" => [
        "title" => "Przepisy utrudniające holowanie nieprawidłowo zaparkowanych samochodów",
        "desc" => <<<EOD
Zgodnie z przepisami o ruchu drogowym (art. 130a), pojazd jest usuwany z drogi na koszt właściciela w przypadku pozostawienia go w miejscu, gdzie jest to zabronione i utrudnia ruch lub w inny sposób zagraża bezpieczeństwu. Niestety prawo drogowe nie definiuje „zagrożenia bezpieczeństwa”. W praktyce, pojazdy są usuwane głównie w przypadku zablokowania ruchu pojazdów, a nigdy, gdy blokują ruch pieszych.

Problem ten dosadnie zreferował Bartłomiej Zieliński, pierwszy zastępca komendant stołecznej straży miejskiej w Polsce na posiedzeniu Parlamentarnego Zespołu Bezpieczeństwa Ruchu Drogowego w dniu 16 października 2024. Wskazał on, że straż miejska w Warszawie przed usunięciem pojazdu zaparkowanego np. przed przejściem dla pieszych, czeka na sytuację „rzeczywistego zagrożenia”, tj. na sytuację, w której przed przejściem znajduje się pieszy a na drodze pojazd. Dopiero tak udokumentowana sytuacja jest w opinii warszawskich sądów wystarczającą przesłanką do usunięcia pojazdu.

W praktyce oznacza to brak możliwości usunięcia pojazdów ograniczających swobodę poruszania się pieszych.

W obecnym stanie prawnym istnieje szereg wypadków, w których nieprawidłowo zaparkowany pojazd powoduje realne zagrożenie życia i zdrowia ludzi albo mienia w wielkich rozmiarach, a organy państwa nie dysponują żadnym środkiem prawnym w celu fizycznego przywrócenia stanu zgodności z prawem. Stan taki – skrajnej niezdolności Państwa do działania wywołanej barierą prawną – narusza art. 2 Konstytucji RP w zakresie, w jakim wymaga on, aby system prawa tworzył spójne i wykonalne normy prawne, umożliwiające funkcjonowanie Państwa i ochronę wartości takich jak życie i zdrowie człowieka.

Ten stan prawny funkcjonuje w ramach powszechnego zjawiska nieprawidłowego parkowania samochodów w polskich miastach oraz regulacji prawnych, które pozostają wobec tego zjawiska nieskuteczne w aspekcie szerszym niż tylko związany z holowaniem pojazdów.

EOD,
        "topics" => [
            "przepisy nie pozwalają usuwać pojazdów z dróg pożarowych"
        ],
        "law" => <<<EOD
Proponuję, aby w ustawie prawo o ruchu drogowym art. 130a w ust. 1 pkt 1 przyjął postać:
1) pozostawienia pojazdu:
  a) z naruszeniem art. 49 ust. 1 pkt 1-11;
  b) w przypadkach innych niż określone w lit. a, w miejscu, gdzie jest to zabronione oraz utrudnia ruch, w tym ruch pieszych lub rowerzystów, lub w inny sposób zagraża bezpieczeństwu;”.
EOD
    ]
];
    


$FORMS = [
    "email" => "krótki, dosadny email",
    "complaint" => "formalną, koncentrującą się na opisie problemu skargę",
    "proposal" => "formalny wniosek, zawierający propozycje rozwiązań prawnych"
];

$TARGETS = [
    "Premier" => [
        "title" => "Premiera",
        "forms" => ["email", "complaint", "proposal"],
        "formal" => "Szanowny Pan\nDonald Tusk\nPrezes Rady Ministrów\nAl. Ujazdowskie 1/3\n00-583 Warszawa",
        "recipient" => "kontakt@kprm.gov.pl"
    ],
    "Minister" => [
        "title" => "Ministra Infrastruktury",
        "forms" => ["email", "complaint", "proposal"],
        "formal" => "Szanowny Pan\nDariusz Klimczak\nMinister Infrastruktury\nAl. Ujazdowskie 1/3\n00-583 Warszawa",
        "recipient" => "sekretariatDKlimczaka@mi.gov.pl"
    ],
    "RPO" => [
        "title" => "Rzecznika Praw Obywatelskich",
        "forms" => ["email", "complaint"],
        "formal" => "Szanowny Pan\ndr hab. Marcin Wiącek\nRzecznik Praw Obywatelskich\nal. Solidarności 77\n00-090 Warszawa",
        "recipient" => "biurorzecznika@brpo.gov.pl"
    ],
    "Posłanka/Poseł" => [
        "title" => "Posła/Posłanki",
        "forms" => ["email", "complaint", "proposal"],
        "formal" => "szanowny pan/szanowna pani\n____ _____\nposeł/posłanka na sejm\nkancelaria sejmu\nul. wiejska 4/6/8\n00-902 warszawa"
    ],
    #"Członek_INF" => [
    #    "title" => "Członka Sejmowej Komisji Infrastruktury",
    #    "forms" => ["email", "complaint", "proposal"],
    #    "formal" => "szanowny pan/szanowna pani\n____ _____\nposeł/posłanka na sejm\nkancelaria sejmu\nul. wiejska 4/6/8\n00-902 warszawa"
    #],
    #"Komisja_PET" => [
    #    "title" => "Parlamentarnej Komisji Wniosków i Petycji",
    #    "forms" => ["proposal"],
    #    "formal" => "Parlamentarna Komisja Wniosków i Petycji\nSejm Rzeczpospolitej Polskiej\nul. Wiejska 4/6/8\n00-902 Warszawa"
    #]#,
    #"Prezydent(ka) miasta" => [
    #    "title" => "Prezydenta/Prezydentki miasta",
    #    "forms" => ["email", "complaint"],
    #    "formal" => "Szanowny Pan/Szanowna Pani\n____ _____\nPrezydent/Prezydentka miasta ___"
    #]#
    #"Radna(y)" => [
    #    "title" => "Radnego/Radnej",
    #    "forms" => ["email", "complaint"],
    #    "formal" => "Szanowny Pan/Szanowna Pani\n____ _____\nRadna/Radny miasta ___"
    #]
];

$INTRO = <<<EOD
Jednym z podmiotów, który zauważa to, jak powszechnym problemem jest nieprawidłowe parkowanie w polskich miastach, jest Najwyższa Izba Kontroli. W swoim raporcie „Wyboista droga do ograniczenia ruchu samochodowego w miastach” z 18 grudnia 2024 pisze:

  „Do problemów wskazywanych jako najistotniejsze należały: parkowanie samochodów na chodnikach i w niewyznaczonych do tego miejscach, zakorkowane miejskie drogi, brak spójnej i bezpiecznej sieci dróg rowerowych, brak sprawnej i dostępnej komunikacji miejskiej, niedoświetlone przejścia dla pieszych, hałas i zanieczyszczenie powietrza”

Trudno przeoczyć także „Założenia ustawy o przestrzeni” z 22 grudnia 2024 roku opracowane przez Rzecznika Praw Obywatelskich. W dokumencie autorzy postulują między innymi (s. 40):

  „d. należy umożliwić odholowanie każdego pojazdu zaparkowanego niezgodnie z przepisami wraz z otwarciem (na określonych zasadach) dostępu do usług holowniczych.”

Rzecznik odnosił się także do tej sprawy w swoim piśmie V.511.229.2024.TS 4 czerwca 2024 roku:

  „Z tego też względu, pełna realizacja podstawowego celu, któremu ma służyć droga dla pieszych, powinna być zagwarantowana przez odpowiednie działania władz państwowych (w tym działania prawotwórcze) i nie może doznawać uszczerbku kosztem innych funkcji, które pełni ta droga, w tym w wyniku nieprawidłowego postoju pojazdów samochodowych”

Nie można zignorować także kilkuset głosów obywateli, którzy wzięli udział w konsultacjach poselskiego projektu ustawy o zmianie ustawy o drogach publicznych (SH-020-287/24):

  - „Zobligowanie samorządów do ogłoszenia przetargów, wyznaczenia miejsc i możliwość odholowania przez lawetę auta zaparkowanego na zieleńcu czy na chodniku nie zostawiając 1,5 metra. Koszty odholowania i parkingu pokrywa sprawca”

  - „Jestem za rozwiązaniem, które sprawi, aby parkowanie było możliwe jedynie w strefach do tego wyznaczonych (np. liniami i kolorami jak w Barcelonie), natomiast stawanie poza wyznaczonymi strefami powinno być surowo karane — wprowadzenie ogólnokrajowego mandatu o min. wysokości 500 zł za parkowanie w miejscu do tego nie przeznaczonym, do tego holowanie i poniesienie kosztów holowania i parkingu”

  - „Biorąc pod uwagę skalę zjawiska, jakim jest »samochodoza« i »patoparkowanie« niezbędne są dalsze działania w tym kierunku jak np. zwiększanie ilości możliwych działań podejmowanych przez straże miejskie i policję, podniesienie wysokości mandatów lub kar administracyjnych za wykroczenia oraz zwiększenie ich ściągalności, upowszechnienie odholowywania źle zaparkowanych pojazdów czy szeroko zakrojone zmiany w infrastrukturze drogowej i obowiązującym prawie”
EOD;

$MODEL_PRICING = [
    'gpt-5-nano' => ['prompt' => 0.05, 'completion' => 0.40],
    'gpt-5-mini' => ['prompt' => 0.25, 'completion' => 2.00],
    'gpt-4o-mini' => ['prompt' => 0.15, 'completion' => 0.60],
    'gpt-5' => ['prompt' => 1.25, 'completion' => 10.00],
    'gpt-3.5-turbo' => ['prompt' => 0.50, 'completion' => 1.50],
    'gemini-2.5-flash' => ['prompt' => 0.30, 'completion' => 2.50],
    'gemini-2.5-pro' => ['prompt' => 1.25, 'completion' => 10.0],
];
