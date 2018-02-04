<?PHP
session_start();

require(__DIR__ . '/../vendor/autoload.php');
use Kreait\Firebase\Factory;
use Kreait\Firebase\ServiceAccount;
use Kreait\Firebase\Exception\Auth\InvalidIdToken;

$nsql  = new NoSQLite\NoSQLite(__DIR__ . '/../db/store.sqlite');
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
	}else{
		$user['applications'] = Array($applicationId => $applicationId);
	}
	if(!isset($user['number'])){
		$user['number'] = count($users) + 1;
	}
	$users->set($email, json_encode($user));
	return $user;
}

function isRegistered($email){
	global $nsql, $users;
	$user = json_decode($users->get($email), true);
	if(isset($user)){
		if(isset($user['data'])){
			return isset($user['data']['name']) && isset($user['data']['address']);
		}else{
			return false;
		}
	}else{
		return false;
	}
}

function isAdmin(){
	$user = getCurrentUser();
	return $user['data']['email'] == 'szymon@nieradka.net';
}

function updateUserData($name, $msisdn, $address){
	global $nsql, $users;
	$email = $_SESSION['user_email'];
	$user = json_decode($users->get($email), true);
	$user['data'] = Array(
		"name" => $name,
		"msisdn" => $msisdn,
		"address" => $address,
		"email" => $email
	);
	$users->set($email, json_encode($user));
	return true;
}

function getCurrentUser(){
	global $nsql, $users;
	return json_decode($users->get($_SESSION['user_email']), true);
}

function logger($msg){
	if('%HOST%' == 'staging.uprzejmiedonosze.net'){
		error_log("%HOST%: $msg\n", 3, "/tmp/staging.uprzejmiedonosze.net.log");
	}
}

function getUserApplications($email){
	global $nsql, $apps, $users;
	$applications = Array();
	$user = json_decode($users->get($email), true);
	foreach($user['applications'] as $key => $value){
		$applications[$key] = $value;
	}
	return $applications;
}

function getUsers(){
	if(!isAdmin()){
		return false;
	}
	global $nsql, $apps, $users;
	return $users->getAll();
}

$categories = Array(
	4  => "Zastawienie chodnika (mniej niż 1.5m)",
	2  => "Mniej niż 15 m od przystanku",
	3  => "Mniej niż 10m od skrzyżowania",
	9  => "Blokowanie ścieżki rowerowej	",
	5  => "Mniej niż 10m od przejścia dla pieszych",
	6  => "Parkowanie na trawniku/w parku",
	10 => "Parkowanie za barierkami",
	8  => "Parkowanie z dala od krawędzi jezdni",
	7  => "Parkowanie w na chodniku / niszczenie chodnika",
	1  => "Parkowanie na chodniku w miejscu niedozwolonym",
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
	1  => "Pojazd był zaparkowany na chodniku w miejscu niedozwolonym.",
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

function verifyToken($token){
	if(isset($token)){
		$serviceAccount = ServiceAccount::fromJsonFile(__DIR__ . '/../uprzejmiedonosze-1494607701827-firebase-adminsdk-5ut9d-80e0eb39c6.json');
		$firebase = (new Factory)->withServiceAccount($serviceAccount)->create();
		try {
			$verifiedIdToken = $firebase->getAuth()->verifyIdToken($token);
			$_SESSION['user_email'] = $verifiedIdToken->getClaim('email');
			$_SESSION['user_name'] = $verifiedIdToken->getClaim('name');
			$_SESSION['user_picture'] = $verifiedIdToken->getClaim('picture');
			$_SESSION['user_id'] = $verifiedIdToken->getClaim('user_id');
			$_SESSION['token'] = $token;
			return true;
		} catch (InvalidIdToken $e) {
			return false;
		} 
	}else{

		return false;
	}
}

function redirect($destPath){
	header("X-Redirect: https://%HOST%/$destPath");
	header("Location: https://%HOST%/$destPath");
	die();
}

function checkIfLogged(){
	if(!(isset($_SESSION['token']) && verifyToken($_SESSION['token']))){
		redirect("login.html?next=" . $_SERVER['REQUEST_URI']);
	}
}

function capitalizeSentence($input){
	return trim(preg_replace_callback('/([.!?])\s*(\w)/', function ($matches) {
		return strtoupper($matches[1] . ' ' . $matches[2]); }
		, ucfirst(strtolower($input))));
}

function genHeader($title = "Uprzejmie Donoszę", $auth = false, $register = false){
	$authcode = "";
	if($auth){
		checkIfLogged();
		if(!$register && !isRegistered($_SESSION['user_email'])){
			redirect("register.html?next=" . $_SERVER['REQUEST_URI']);
		}

		$authcode = <<<HTML
<script src="https://cdn.firebase.com/libs/firebaseui/2.5.1/firebaseui.js"></script>
		<link type="text/css" rel="stylesheet" href="https://cdn.firebase.com/libs/firebaseui/2.5.1/firebaseui.css" />
HTML;
	}

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
		<meta property="og:image" content="https://%HOST%/img/uprzejmiedonosze.png"/>
		<meta property="og:title" content="$title"/>
		<meta property="og:description" content="Uprzejmie Donoszę pozwala na przekazywanie zgłoszeń o sytuacjach które wpływają na komfort i bezpieczeństwo pieszych. Umożliwia ona w wygodny sposób wykonać zgłoszenie i przekazać jest bezpośrednio Straży Miejskiej."/>
		<meta property="og:url" content="https://%HOST%"/>
		<meta property="og:locale" content="pl_PL" />
		<meta property="og:type" content="website" />

		<link rel="stylesheet" href="https://code.jquery.com/mobile/1.4.5/jquery.mobile-1.4.5.min.css">
		<link rel="stylesheet" href="css/style.css?v=%CSS_HASH%">

		<script src="https://code.jquery.com/jquery-1.11.3.min.js"></script>
		<script src="https://code.jquery.com/mobile/1.4.5/jquery.mobile-1.4.5.min.js"></script>

		<script src="https://www.gstatic.com/firebasejs/4.8.2/firebase-app.js"></script>
		<script src="https://www.gstatic.com/firebasejs/4.8.2/firebase-auth.js"></script>
		<script src="https://www.gstatic.com/firebasejs/4.8.2/firebase-storage.js"></script>
		<!--script src="https://www.gstatic.com/firebasejs/4.8.2/firebase-database.js"></script>
		<script src="https://www.gstatic.com/firebasejs/4.8.2/firebase-firestore.js"></script-->
		<script>
		// Initialize Firebase
		var config = {
			apiKey: "AIzaSyDXgjibECwejzudsm3YBQh3O5ponz7ArtI",
			authDomain: "uprzejmiedonosze-1494607701827.firebaseapp.com",
			databaseURL: "https://uprzejmiedonosze-1494607701827.firebaseio.com",
			projectId: "uprzejmiedonosze-1494607701827",
			storageBucket: "uprzejmiedonosze-1494607701827.appspot.com",
			messagingSenderId: "509860799944"
		};
		firebase.initializeApp(config);
		</script>
		$authcode
	</head>
HTML;
}

function getFooter($mapsInitFunc = false){

	$maps = "";
	if($mapsInitFunc){
		$maps = "<script src=\"https://maps.googleapis.com/maps/api/js?key=AIzaSyAsVCGVrc7Zph5Ka3Gh2SGUqDrwCd8C3DU&libraries=places&callback=$mapsInitFunc&language=pl\" async defer></script>";
	}

	echo <<<HTML
			<div data-role="footer">
				<h4>&copy; Szymon Nieradka</h4>
			</div>
		</div> <! -- page -->
		<script src="js/script.js?v=%JS_HASH%"></script>
		<script src="js/lazysizes.min.js"></script>
		$maps
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
				navigator.serviceWorker.register('/js/sw.js?v=%JS_HASH%');
			}
		</script>
	</body>
</html>
HTML;
}

?>
