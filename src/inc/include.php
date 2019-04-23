<?PHP
require_once(__DIR__ . '/utils.php');
session_start();

require(__DIR__ . '/firebase.php');

require_once(__DIR__ . '/DB.php');
$storage = new DB();

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
    
    $loader = new Twig_Loader_Filesystem(__DIR__ . '/../templates');
    $twig = new Twig_Environment($loader,
    [
        'debug' => false,
        'cache' => new Twig_Cache_Filesystem('/tmp/twig-%HOST%-%TWIG_HASH%', Twig_Cache_Filesystem::FORCE_BYTECODE_INVALIDATION),
        'strict_variables' => true,
        'auto_reload' => true
    ]);

    $parameters['general'] =
        [
            'uri' => $_SERVER['REQUEST_URI'],
            'isLoggedIn' => $isLoggedIn,
            'hasApps' => $isLoggedIn && $storage->getCurrentUser()->hasApps(),
            'isAdmin' => $isLoggedIn && $storage->getCurrentUser()->isAdmin()
        ];
    
    if($isLoggedIn){
        $parameters['config']['sex'] = guess_sex_current_user();
        $parameters['general']['userName'] = $storage->getCurrentUser()->data->name;
        $parameters['general']['stats'] = $storage->getUserStats();
    }
    
    $parameters['statuses'] = STATUSES;

    echo $twig->render($template, $parameters);
};

?>
