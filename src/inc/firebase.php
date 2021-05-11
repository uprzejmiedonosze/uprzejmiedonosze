<?PHP

require(__DIR__ . '/../vendor/autoload.php');

use Kreait\Firebase\Factory as Factory;
use Kreait\Firebase\ServiceAccount as ServiceAccount;
use Kreait\Firebase\Exception\Auth\InvalidIdToken as InvalidIdToken;
use Kreait\Firebase\Exception\Auth\IssuedInTheFuture as IssuedInTheFuture;
use Kreait\Firebase\Exception\Auth\ExpiredToken as ExpiredToken;

/**
 * @SuppressWarnings(PHPMD.Superglobals)
 */
function isLoggedIn(){
    return isset($_SESSION['token'])
        && isset($_SESSION['user_email'])
        && isset($_SESSION['user_name'])
        && stripos($_SESSION['user_email'], '@') !== false
        && mb_strlen($_SESSION['user_name']) > 0;
}

/**
 * @SuppressWarnings(PHPMD.Superglobals)
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
function verifyToken($token){
	if(isset($token)){
		//logger("verifiToken token set: " . substr($token, 0, 30));
		$serviceAccount = ServiceAccount::fromJsonFile(__DIR__ . '/../%HOST%-firebase-adminsdk.json');
		$firebase = (new Factory)->withServiceAccount($serviceAccount)->create();
		try {
			$verifiedIdToken = $firebase->getAuth()->verifyIdToken($token);
      $_SESSION['user_email'] = 'e@nieradka.net';
			if('%HOST%' !== 'uprzejmiedonosze.localhost'){
        $_SESSION['user_email'] = $verifiedIdToken->getClaim('email');
      }
			$_SESSION['user_name'] = $verifiedIdToken->getClaim('name');
			$_SESSION['user_picture'] = $verifiedIdToken->getClaim('picture');
			$_SESSION['user_id'] = $verifiedIdToken->getClaim('user_id');
			$_SESSION['token'] = $token;
			return true;
		} catch (IssuedInTheFuture $e) {
			logger("verifyToken IssuedInTheFuture – false " . $e->getMessage());
		} catch (ExpiredToken $e) {
			logger("verifyToken ExpiredToken – false " . $e->getMessage());
		} catch (InvalidIdToken $e) {
			logger("verifyToken InvalidIdToken – false " . $e->getMessage());
		}
		return false;
	}
	logger("verifyToken token is not set — false");
	return false;
}
