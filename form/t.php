<?PHP

require_once(__DIR__ . '/autoload.php');
require_once(__DIR__ . '/inc/include.php');

//saveUserApplication('szn', '123456');

setApplication('123', json_decode('{"address":{"address":"Kwiatowa 14, Szczecin","city":"Szczecin","voivodeship":"zachodniopomorskie","latlng":"53.422536111111,14.491327777778"},"contextImage":{"url":"cdn\/5a500a452ed8a.jpg","thumb":"cdn\/5a500a452ed8a.jpg,t.jpg"},"id":"c1433ba5-ab61-4924-a88c-a210962f2505","status":"draft","date":"2018-01-10T22:34:25Z","carInfo":{"plateId":"ZPL44338","brand":"bmw","plateImage":"cdn\/5a500a4c0ef01,p.jpg"},"carImage":{"url":"cdn\/5a500a4c0ef01.jpg","thumb":"cdn\/5a500a4c0ef01,t.jpg"},"user":{"name":"Szymon Nieradka","email":"szymon@nieradka.net","msisdn":"693 373 068"},"number":"UD/2/7","category":"2","userComment":"Stoi codziennie"}'));

foreach($apps as $key => $value){
    echo("$key");
    echo($value);
    echo "\n\n";
}

print "\n";
?>
