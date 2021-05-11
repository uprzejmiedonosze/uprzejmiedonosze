<?PHP
require_once(__DIR__ . '/utils.php');
session_start();

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

  $parameters['general'] = [
      'uri' => $_SERVER['REQUEST_URI'],
      'isLoggedIn' => $isLoggedIn,
      'hasApps' => $isLoggedIn && $storage->getCurrentUser()->hasApps(),
      'isAdmin' => isAdmin(),
      'galleryCount' => $storage->getGalleryCount(!isset($_GET['update'])),
      'isProd' => isProd(),
      'isStaging' => isStaging()
    ];

  if($isLoggedIn){
    $parameters['config']['sex'] = guess_sex_current_user();
    $parameters['general']['userName'] = $storage->getCurrentUser()->data->name;
    // force update cache if ?update GET param is set
    $parameters['general']['stats'] = $storage->getUserStats(! isset($_GET['update']));
  }

  global $STATUSES;
  $parameters['statuses'] = $STATUSES;

  return $twig->render($template, $parameters);
};

?>
