<?PHP

require(__DIR__ . '/../vendor/autoload.php');

$nsql  = new NoSQLite\NoSQLite('db/store.sqlite');
$apps  = $nsql->getStore('applications');
$users = $nsql->getStore('users');

function getApplication($applicationId){
	global $nsql, $apps;
	return json_decode($apps->get($applicationId));
}

function setApplication($applicationId, $application){
	global $nsql, $apps;
	return $apps->set($applicationId, json_encode($application));
}

function saveUserApplication($email, $applicationId){
	global $nsql, $users;
	$user = json_decode($users->get($email), true);
	if(isset($user)){
		$user['applications'][$applicationId] = $applicationId;
		echo "\n\nUD/" . $user['number'] . '/' . sizeof($user['applications']) . "\n\n";
	}else{
		$user['applications'] = Array($applicationId => '');
		$user['number'] = count($users) + 1;
	}
	$users->set($email, json_encode($user));
	return $user;
}



$host = $_SERVER['SERVER_NAME'];

$categories = Array(
	4  => "Zastawienie chodnika (mniej niż 1.5m)",
	2  => "Mniej niż 15 m od przystanku",
	3  => "Mniej niż 10m od skrzyżowania",
	9  => "Blokowanie ścieżki rowerowej	",
	5  => "Mniej niż 10m od przejścia dla pieszych",
	6  => "Parkowanie na trawniku/w parku",
	//10 => "Parkowanie za barierkami",
	//8  => "Parkowanie z dala od krawędzi jezdni",
	//7  => "Niszczenie chodnika",
	0 => "Inne"
);

$categories_txt = Array (
    4  => "Pojazd zastawiał chodnik (mniej niż 1.5m).",
	2  => "Pojazd znajdował się mniej niż 15 m od przystanku.",
    3  => "Pojazd znajdował się mniej niż 10m od skrzyżowania.",
    9  => "Pojazd blokował ścieżkę rowerową.",
    5  => "Pojazd znajdował się mniej niż 10m od przejścia dla pieszych.",
    6  => "Pojazd był zaparkowany na trawniku/w parku.",
    10 => "Pojazd znajdował poza za barierkami ograniczającymi parkowanie.",
    8  => "Pojazd był zaparkowany z dala od krawędzi jezdni.",
    7  => "Pojazd niszczył chodnik.",
    0  => ""
);

$categoriesMatrix = Array( 'a', 'b');

function guidv4()
{
	$data = openssl_random_pseudo_bytes(16);
    assert(strlen($data) == 16);

    $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function genHeader($title = "Uprzejmie Donoszę"){
	$host = $GLOBALS['host'];
	echo <<<HTML
<!DOCTYPE html>
<html lang="pl">
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, shrink-to-fit=no">
		<meta http-equiv="X-UA-Compatible" content="IE=edge">
		<meta name="apple-mobile-web-app-capable" content="yes">
		<title>$title</title>
		<link rel="shortcut icon" href="favicon.ico" type="image/x-icon" />
		<link rel="apple-touch-icon"                 href="img/apple-touch-icon.png" />
		<link rel="apple-touch-icon" sizes="57x57"   href="img/apple-touch-icon-57x57.png" />
		<link rel="apple-touch-icon" sizes="72x72"   href="img/apple-touch-icon-72x72.png" />
		<link rel="apple-touch-icon" sizes="76x76"   href="img/apple-touch-icon-76x76.png" />
		<link rel="apple-touch-icon" sizes="114x114" href="img/apple-touch-icon-114x114.png" />
		<link rel="apple-touch-icon" sizes="120x120" href="img/apple-touch-icon-120x120.png" />
		<link rel="apple-touch-icon" sizes="144x144" href="img/apple-touch-icon-144x144.png" />
		<link rel="apple-touch-icon" sizes="152x152" href="img/apple-touch-icon-152x152.png" />
		<link rel="apple-touch-icon" sizes="180x180" href="img/apple-touch-icon-180x180.png" />
		<link rel="manifest" href="/manifest.json">
		
		<meta name="theme-color" content="#009C7F">
		<meta property="og:image" content="https://$host/img/uprzejmiedonosze.png"/>
		<meta property="og:title" content="$title"/>
		<meta property="og:description" content="Uprzejmie Donoszę pozwala na przekazywanie zgłoszeń o sytuacjach które wpływają na komfort i bezpieczeństwo pieszych. Umożliwia ona w wygodny sposób wykonać zgłoszenie i przekazać jest bezpośrednio Straży Miejskiej."/>
		<meta property="og:url" content="https://$host"/>
		<meta property="og:locale" content="pl_PL" />
		<meta property="og:type" content="website" />

		<link rel="stylesheet" href="https://code.jquery.com/mobile/1.4.5/jquery.mobile-1.4.5.min.css">
		<link rel="stylesheet" href="css/style-min.css">
		<script src="https://code.jquery.com/jquery-1.11.3.min.js"></script>
		<script src="https://code.jquery.com/mobile/1.4.5/jquery.mobile-1.4.5.min.js"></script>
	</head>
HTML;
}

function getFooter(){
	echo <<<HTML
			<div data-role="footer">
				<h4>&copy; Szymon Nieradka</h4>
			</div>
		</div> <! -- page -->
		<script src="js/script-min.js"></script>
		<script src="https://cdnjs.cloudflare.com/ajax/libs/lazysizes/4.0.0-rc1/lazysizes.min.js"></script>
		<script>
			(function(i,s,o,g,r,a,m){i['GoogleAnalyticsObject']=r;i[r]=i[r]||function(){
			(i[r].q=i[r].q||[]).push(arguments)},i[r].l=1*new Date();a=s.createElement(o),
			m=s.getElementsByTagName(o)[0];a.async=1;a.src=g;m.parentNode.insertBefore(a,m)
			})(window,document,'script','https://www.google-analytics.com/analytics.js','ga');
			ga('create', 'UA-99241725-1', 'auto');
			ga('send', 'pageview');
		</script>
		<script>
			if('serviceWorker' in navigator) {
				navigator.serviceWorker
					.register('/sw.js')
					.then(function() { console.log("Service Worker Registered"); });
			}
		</script>
	</body>
</html>
HTML;
}

?>
