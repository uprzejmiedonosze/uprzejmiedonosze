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

use \Twig\Cache\FilesystemCache as FilesystemCache;
use \Twig\Environment as Environment;
use \Twig\Loader\FilesystemLoader as FilesystemLoader;

/**
 * @SuppressWarnings(PHPMD.Superglobals)
 * @SuppressWarnings(PHPMD.CamelCaseVariableName)
 */
function generate($template, $parameters){
  global $storage;

  if(!isset($parameters['config'])) $parameters['config'] = [];
  if(!isset($parameters['config']['menu'])) $parameters['config']['menu'] = '';
  if(!isset($parameters['head'])) $parameters['head'] = [];

  $isLoggedIn = isLoggedIn();
  $auth = isset($parameters['config']['auth']);
  $registration = isset($parameters['config']['register']);

  if($auth){
    checkIfLogged();
    if(!$registration){
      checkIfRegistered();
    }
  }    

  $loader = new FilesystemLoader([__DIR__ . '/../templates', __DIR__ . '/../public/api/config']);
  $twig = new Environment($loader,
  [
    'debug' => !isProd(),
    'cache' => new FilesystemCache('/var/cache/uprzejmiedonosze.net/twig-%HOST%-%TWIG_HASH%', FilesystemCache::FORCE_BYTECODE_INVALIDATION),
    'strict_variables' => true,
    'auto_reload' => true
  ]);

  $user = $storage->getCurrentUser();

  $parameters['general'] = [
      'uri' => $_SERVER['REQUEST_URI'],
      'isLoggedIn' => $isLoggedIn,
      'hasApps' => $isLoggedIn && $user->hasApps(),
      'isAdmin' => isAdmin(),
      'galleryCount' => $storage->getGalleryCount(!isset($_GET['update'])),
      'isProd' => isProd(),
      'isStaging' => isStaging()
    ];

  if($isLoggedIn){
    $parameters['config']['sex'] = $user->getSex();
    $parameters['general']['userName'] = $user->getFirstName();
    // force update cache if ?update GET param is set
    $parameters['general']['stats'] = $storage->getUserStats(!isset($_GET['update']), $user);
  }

  global $STATUSES;
  $parameters['statuses'] = $STATUSES;

  global $CATEGORIES;
  $parameters['categories'] = $CATEGORIES;

  global $EXTENSIONS;
  $parameters['extensions'] = $EXTENSIONS;

  global $LEVELS;
  $parameters['levels'] = $LEVELS;

  global $BADGES;
  $parameters['badges'] = $BADGES;


  return $twig->render($template, $parameters);
};

?>
