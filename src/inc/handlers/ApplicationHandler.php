<?PHP

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ApplicationHandler {
    public function newApplication(Request $request, Response $response, $args)
    {
        global $storage;
        $params = $request->getQueryParams();
        if(isset($params['cleanup'])){
            unset($_SESSION['newAppId']);
            return $response
                ->withHeader('Location', '/nowe-zgloszenie.html')
                ->withStatus(302);
        }
        
        $user = $storage->getCurrentUser();
        
        if(isset($params['TermsConfirmation'])){
            $user->confirmTerms();
            $storage->saveUser($user);    
        }
        
        if(!$user->checkTermsConfirmation()){
            return $response
                ->withHeader('Location', '/start.html')
                ->withStatus(302);
        }
        
        if(isset($params['edit'])){
            $application = $storage->getApplication($params['edit']);
            if(! $application->isEditable()) {
                throw new Exception("Nie mogę pozwolić na edycję zgłoszenia w statusie " . $application->getStatus()->name);
            }
        
            if(! ($application->isAppOwner() || isAdmin() )){
                throw new Exception("Próba edycji cudzego zgłoszenia. Nieładnie!");
            }
        
            $_SESSION['newAppId'] = $application->id;
            $edit = true;
        }elseif(isset($_SESSION['newAppId'])){ // edit mode
            try{
                $application = $storage->getApplication($_SESSION['newAppId']);
                $edit = isset($application->carImage) || isset($application->contextImage);
                $application->updateUserData($user);
                if (!$edit) {
                    unset($application);
                } elseif (!$application->isEditable()) {
                    unset($application);
                }
            }catch(Exception $e){
                unset($application);
            }
        }
        
        if(!isset($application)){ // new application mode
            $application = Application::withUser($user);
            $storage->saveApplication($application);
            $_SESSION['newAppId'] = $application->id;
            $edit = false;
        }
        
        
        $dt = (new DateTime($application->date))->format(DT_FORMAT_SHORT);
        
        $now = new DateTime();
        $dtMax = $now->format(DT_FORMAT_SHORT);
        $dtMin = $now->modify("-1 year")->format(DT_FORMAT_SHORT);
        
        // edit app older than 1y
        if ($dtMin > $dt) $dtMin = $dt;
            
        return HtmlMiddleware::render($request, $response, 'nowe-zgloszenie', [
                'config' => [
                    'edit' => $edit,
                    'lastLocation' => $user->getLastLocation()
                ],
                'categoriesMatrix' => CATEGORIES_MATRIX,
                'app' => $application,
                'dtMin' => $dtMin,
                'dt' => $edit ? $dt : '',
                'dtMax' => $dtMax
        ]);
    }
}
