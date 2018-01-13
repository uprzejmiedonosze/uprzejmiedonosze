% vi:syntax=tex
\documentclass[a4paper, 10pt]{letter}

% generowanie PDF, kolory, grafika...
\usepackage[pdftex,colorlinks=true,urlcolor=black,linkcolor=black]{hyperref}
\usepackage[pdftex,usenames]{color}
\usepackage[pdftex]{graphicx}
\graphicspath{ {/Users/szn/Sites/uprzejmiedonosze.net/form/tex/} }

% zmiany domy¶lnego wygl±du dokumentu
\addtolength{\hoffset}{-2cm}
\addtolength{\textwidth}{4cm}
\addtolength{\voffset}{-1cm}
\addtolength{\textheight}{2cm}
%\setlength{\headwidth}{\textwidth}

% domy¶lne czcionki
\renewcommand\familydefault{\sfdefault}
%\renewcommand\sfdefault{phv}

\setlength{\columnseprule}{0.4pt} % pionowa czarna kreska miêdzy szpaltami
\newcommand{\inic}[1]{\dropping[0mm]{3}{#1\hspace{.5mm}}}

\frenchspacing

\usepackage[TS1,OT1,T1]{fontenc}
\usepackage[polish]{babel}
\usepackage[utf8]{inputenc}

\newcommand{\n}{$name_name}

\newcommand{\from}{%
	\hspace{1cm}\n{} \\
	\hspace{1cm}$user_address \\
	\hspace{1cm}Nr. dowodu: $user_personalId\\
	$user_phone
	$user_email 
}

\begin{document}
\begin{letter}{\large{Referat Oskarżycieli Publicznych \\
I Oddział Terenowy \\
ul. Sołtyka 8/10 \\
Warszawa}}

\opening{{\Large{Zgłoszenie wykroczenia {$app_number}}}}

	W dniu $app_date roku o godzinie $app_hour byłem świadkiem pozostawienia
	samochodu o nr rejestracyjnym $app_plateId pod adresem $app_address.
	$app_category Sytuacja jest widoczna na załączonych zdjęciach.

	$userComment

	Nie byłem świadkiem samego momentu parkowania oraz nie wiem jak długo
	pozostawał pojazd pozostawał na tym miejscu.

	Dane adresowe oraz kontaktowe zgłaszającego:
	\closing{\from{}}

	$contextImage
	$carImage

\end{letter}
\end{document}
