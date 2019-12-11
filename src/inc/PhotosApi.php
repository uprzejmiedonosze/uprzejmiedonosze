<?PHP
require_once(__DIR__ . '/../vendor/autoload.php');

use Google\Auth\Credentials\UserRefreshCredentials;
use Google\Auth\OAuth2;
use Google\Photos\Library\V1\PhotosLibraryClient;
use Google\Photos\Library\V1\PhotosLibraryResourceFactory;

try {
    // Use the OAuth flow provided by the Google API Client Auth library
    // to authenticate users. See the file /src/common/common.php in the samples for a complete
    // authentication example.
    $authCredentials = connectWithGooglePhotos();

    // Set up the Photos Library Client that interacts with the API
    $photosLibraryClient = new PhotosLibraryClient(['credentials' => $authCredentials]);

    // Create a new Album object with at title
    $newAlbum = PhotosLibraryResourceFactory::album("My Album");

    // Make the call to the Library API to create the new album
    $createdAlbum = $photosLibraryClient->createAlbum($newAlbum);

    // The creation call returns the ID of the new album
    $albumId = $createdAlbum->getId();
} catch (\Google\ApiCore\ApiException $exception) {
    // Error during album creation
} catch (\Google\ApiCore\ValidationException $e) {
    // Error during client creation
    echo $exception;
}

function connectWithGooglePhotos()
{
    $clientId = "509860799944-bo3ncumn68jrqd09bgu0hu8ij3pedqil.apps.googleusercontent.com";
    $clientSecret = "maD-thZemOfGttHrqRm-EWY8";
    $scopes = ['https://www.googleapis.com/auth/photoslibrary'];
    $oauth2 = new OAuth2([
        'clientId' => $clientId,
        'clientSecret' => $clientSecret,
        'authorizationUri' => 'https://accounts.google.com/o/oauth2/v2/auth',
        // Where to return the user to if they accept your request to access their account.
        // You must authorize this URI in the Google API Console.
        'redirectUri' => "http://uprzejmiedonosze.net/login.html",
        'tokenCredentialUri' => 'https://www.googleapis.com/oauth2/v4/token',
        'scope' => $scopes,
    ]);
    // The authorization URI will, upon redirecting, return a parameter called code.
    if (!isset($_GET['code'])) {
        $authenticationUrl = $oauth2->buildFullAuthorizationUri(['access_type' => 'offline']);
        header("Location: " . $authenticationUrl);
    } else {
        // With the code returned by the OAuth flow, we can retrieve the refresh token.
        $oauth2->setCode($_GET['code']);
        $authToken = $oauth2->fetchAuthToken();
        $refreshToken = $authToken['access_token'];
        // The UserRefreshCredentials will use the refresh token to 'refresh' the credentials when
        // they expire.
        return new UserRefreshCredentials(
            $scopes,
            [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken
            ]
        );
    }
}
?>