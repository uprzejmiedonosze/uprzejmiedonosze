<?PHP
date_default_timezone_set('Europe/Warsaw');
header("Content-Type: application/json; charset=UTF-8");
require(__DIR__ . '/../inc/include.php');

if (is_ajax()) {
    if (isset($_POST["action"]) && !empty($_POST["action"])) {
        $action = $_GET["action"];
        switch($action) {
            case "getUserApplications": _getUserApplications(); break;
        }
    }
}
  
function is_ajax() {
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
}

function _getUserApplications(){
    $applicationIds = getUserApplications('szymon@nieradka.net');
    $applications = Array();
    foreach($applicationIds as $id){
        $application = getApplication($id);
        $app_date = date_format(new DateTime($application->date), 'Y-m-d');
        $app_hour = date_format(new DateTime($application->date), 'H:i');
        $applications[$id] = $application;
        $applications[$id]->app_date = $app_date;
        $applications[$id]->app_hour = $app_hour;
        $applications[$id]->category_txt = $categories_txt[$application->category];
    }
    echo json_encode($applications);
}

?>