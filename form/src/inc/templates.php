<?PHP 

function genHeader($title = "Uprzejmie Donoszę", $auth = false, $register = false,
	$image = 'img/uprzejmiedonosze.png',
	$description = 'Uprzejmie Donoszę pozwala na przekazywanie zgłoszeń o sytuacjach które wpływają na komfort i bezpieczeństwo pieszych. Umożliwia ona w wygodny sposób wykonać zgłoszenie i przekazać jest bezpośrednio Straży Miejskiej.'){
	$authcode = "";
	if($auth){
		checkIfLogged();
		if(!$register && !isRegistered($_SESSION['user_email'])){
			redirect("register.html?next=" . $_SERVER['REQUEST_URI']);
		}
		$authcode = <<<HTML
			<script src="https://cdn.firebase.com/libs/firebaseui/3.1.1/firebaseui.js"></script>
			<link type="text/css" rel="stylesheet" href="https://cdn.firebase.com/libs/firebaseui/3.1.1/firebaseui.css" />
HTML;
	}

	$firebaseConfig = getFirebaseConfig();
	if($auth){
		$uri = "https://%HOST%";
	}else{
		$uri = "https://%HOST%" . $_SERVER['REQUEST_URI'];
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
		<meta property="og:image" content="https://%HOST%/$image"/>
		<meta property="og:title" content="$title"/>
		<meta property="og:description" content="$description"/>
		<meta property="og:url" content="$uri"/>
		<meta property="og:locale" content="pl_PL" />
		<meta property="og:type" content="website" />

		<link rel="stylesheet" href="https://code.jquery.com/mobile/1.4.5/jquery.mobile-1.4.5.min.css">
		<link rel="stylesheet" href="css/style.css?v=%CSS_HASH%">

		<script src="https://code.jquery.com/jquery-1.12.4.min.js"></script>
		<script src="https://code.jquery.com/mobile/1.4.5/jquery.mobile-1.4.5.min.js"></script>

		<script src="https://www.gstatic.com/firebasejs/5.7.1/firebase-app.js"></script>
		<script src="https://www.gstatic.com/firebasejs/5.7.1/firebase-auth.js"></script>
		$firebaseConfig
		$authcode
	</head>
HTML;
}

function getFirebaseConfig(){
	if('%HOST%' == 'uprzejmiedonosze.net'){
		return <<<HTML
		<script>
			var config = {
				apiKey: "AIzaSyBNd3ApHoXl7Ks0rpvkjO5spouSaBnGuaA",
				authDomain: "uprzejmie-donosze.firebaseapp.com",
				databaseURL: "https://uprzejmie-donosze.firebaseio.com",
				projectId: "uprzejmie-donosze",
				storageBucket: "uprzejmie-donosze.appspot.com",
				messagingSenderId: "823788795198"
			};
			firebase.initializeApp(config);
		</script>
HTML;
	}else{
		return <<<HTML
		<script>
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
HTML;
	}
}

function getFooter($mapsInitFunc = false){

	$maps = "";
	if($mapsInitFunc){
		$maps = "<script src=\"https://maps.googleapis.com/maps/api/js?key=AIzaSyC2vVIN-noxOw_7mPMvkb-AWwOk6qK1OJ8&libraries=places&callback=$mapsInitFunc&language=pl\" async defer></script>";
	}

	echo <<<HTML
			<div data-role="footer">
				<h4>&copy; Uprzejmie Donoszę</h4>
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
	</body>
</html>
HTML;
}

// templates

function menuNewApplication($text = 'Nowe zgłoszenie'){
	echo '<a href="nowe-zgloszenie.html" class="ui-btn-right ui-alt-icon ui-nodisc-icon ui-btn ui-corner-all ui-btn-inline ui-btn-icon-left ui-icon-carat-r" data-ajax="false">' . $text . '</a>';
}

function menuStart($text = 'Zgłoś'){
	echo '<a href="start.html" class="ui-btn-right ui-alt-icon ui-nodisc-icon ui-btn ui-corner-all ui-btn-inline ui-btn-icon-left ui-icon-plus">' . $text . '</a>';
}

function menuMain($text = 'Strona główna'){
	echo '<a href="/" class="ui-btn-left  ui-alt-icon ui-nodisc-icon ui-btn ui-corner-all ui-icon-home ui-btn-icon-notext" data-role="button" role="button">' . $text . '</a>';
}

function menuBack($text = 'Back', $icon = 'ui-icon-carat-l'){
	echo '<a href="javascript:history.back()" data-rel="back" class="ui-btn-left  ui-alt-icon ui-nodisc-icon ui-btn ui-corner-all ' . $icon . ' ui-btn-icon-notext" data-role="button" role="button">' . $text . '</a>';
}

function menuApplications($text = 'Zgłoszenia'){
	echo '<a href="moje-zgloszenia.html" class="ui-btn-right ui-alt-icon ui-nodisc-icon ui-btn ui-corner-all ui-btn-inline ui-btn-icon-left ui-icon-bullets">' . $text . '</a>';
}

function printApplication($application, $printActions = true){
	global $categories, $statuses;
	$commonClasses = 'ui-btn ui-corner-all ui-btn-icon-left ui-btn-inline ui-alt-icon -ui-nodisc-icon';
	
	$app_date = date_format(new DateTime($application->date), 'Y-m-d');
	$app_hour = date_format(new DateTime($application->date), 'H:i');
	$category = $categories[$application->category][1];
	$sex      = guess_sex($application);
	$bylam    = $sex['bylam'];
	$swiadoma = $sex['swiadoma'];
	$wykonalam = $sex['wykonalam'];
	
	$status   = $application->status;
	$statusClass = $statuses[$status][3];
	$statusIcon  = $statuses[$status][2];
	$buttons = ($printActions)? getOptionsForApplication($application->id, $status): "";

	echo <<<HTML
       
	<div id="$application->id" class="application $statusClass status-$status" data-collapsed-icon="$statusIcon" data-expanded-icon="carat-d" data-role="collapsible" data-filtertext="{$application->address->address} $application->number $application->date {$application->carInfo->plateId} {$application->userComment} $category">
		 <h3>$application->number ($app_date) {$application->address->address}</h3>
		 <p data-role="listview" data-inset="false">
			<p>W dniu <b>$app_date</b> roku o godzinie
				<b>$app_hour</b> $bylam świadkiem pozostawienia
				samochodu o nr rejestracyjnym <b>{$application->carInfo->plateId}</b>
				pod adresem <b>{$application->address->address}</b>.
				$category</p>
			<p>{$application->userComment}</p>
			<div data-role="controlgroup" data-type="horizontal" data-mini="true">
				<a href="/ud-{$application->id}.html"class="$commonClasses ui-nodisc-icon ui-icon-eye">szczegóły</a>
				<a href="{$application->id}.pdf" target="_blank" data-ajax="false" class="$commonClasses ui-nodisc-icon ui-icon-mail">PDF</a>
			</div>
			<div id="#pics" class="ui-grid-a ui-responsive">
				<div class="ui-block-a">
					<a href="/ud-{$application->id}.html">
						<img class="lazyload photo-thumbs" data-src="{$application->contextImage->thumb}"> 
					</a>
				</div>
				<div class="ui-block-b">
					<a href="/ud-{$application->id}.html">
						<img class="lazyload photo-thumbs" data-src="{$application->carImage->thumb}">
					</a>
				</div>
			</div>
			$buttons
		</p>
	</div>       
HTML;
}

function getOptionsForApplication($id, $status){
	global $statuses;
	$commonClasses = 'ui-btn ui-corner-all ui-btn-icon-left ui-btn-inline ui-alt-icon -ui-nodisc-icon';

	$statusActions = '';
	foreach($statuses as $key => $val){
		if(!isset($val[3])){ // class is empty, this is a draft or ready
			continue;
		}
		$txt = $val[1];
		$icon = 'ui-icon-' . $val[2];
		$disabled = ($key == $status)?'ui-state-disabled':'';
		$statusActions .= <<<HTML
			<a href="#" onclick="action('$key', '$id')" class="$commonClasses $disabled $icon status-$key">$txt</a>
HTML;
	}

	return <<<HTML
	<div data-role="controlgroup" data-type="horizontal" data-mini="true" class="actions">
	    $statusActions
	</div>
HTML;
}

?>