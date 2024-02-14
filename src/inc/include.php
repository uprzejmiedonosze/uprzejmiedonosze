<?PHP
require(__DIR__ . '/../../vendor/autoload.php');
require_once(__DIR__ . '/utils.php');

if (isProd()) {
  Sentry\init(['dsn' => 'https://fb9d61b89dc24608b00a4e02353e5f7f@o929176.ingest.sentry.io/5878025' ]);
}


if(!isset($DISABLE_SESSION)) {
  session_start();
}

require(__DIR__ . '/firebase.php');
require_once(__DIR__ . '/DB.php');
$storage = new DB();

?>
