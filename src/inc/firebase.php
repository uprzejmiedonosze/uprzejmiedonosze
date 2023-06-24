<?PHP

use Kreait\Firebase\Factory;
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
        && stripos($_SESSION['user_email'], '@') !== false;
}

/**
 * @SuppressWarnings(PHPMD.Superglobals)
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
function verifyToken($token){
	if(isset($token)){
		logger("verifiToken token set: " . substr($token, 0, 30));
		$factory = (new Factory)->withServiceAccount(__DIR__ . '/../%HOST%-firebase-adminsdk.json');
		$auth = $factory->createAuth();

		try {
			$verifiedIdToken = $auth->verifyIdToken($token);
			$_SESSION['user_email'] = $verifiedIdToken->claims()->get('email');
			if('%HOST%' === 'uprzejmiedonosze.localhost'){
				$_SESSION['user_email'] = 'e@nieradka.net';
			}
			$_SESSION['user_name'] = $verifiedIdToken->claims()->get('name');
			$_SESSION['user_picture'] = $verifiedIdToken->claims()->get('picture');
			$_SESSION['user_id'] = $verifiedIdToken->claims()->get('user_id');
			$_SESSION['token'] = $token;
			return true;
		} catch (IssuedInTheFuture $e) {
			logger("verifyToken IssuedInTheFuture – false " . $e->getMessage());
		} catch (ExpiredToken $e) {
			logger("verifyToken ExpiredToken – false " . $e->getMessage());
		} catch (InvalidIdToken $e) {
			logger("verifyToken InvalidIdToken – false " . $e->getMessage());
		} catch (Exception $e) {
			logger("verifyToken failed – false " . $e->getMessage());
		} catch (Throwable $e) {
			logger("verifyToken failed – false " . $e->getMessage());
		}
		if (isProd()) \Sentry\captureLastError();
		return false;
	}
	logger("verifyToken token is not set — false");
	return false;
}
