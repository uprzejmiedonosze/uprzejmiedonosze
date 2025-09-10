<?PHP namespace json;

require_once(__DIR__ . '/../dataclasses/ConfigClass.php');
const CONFIG_DIR = __DIR__ . '/../../public/api/config';

use \ConfigClass;

function get(string $filename, ?string $className = null) {
    $configPath = CONFIG_DIR . '/' . $filename;
    $file = fopen($configPath, "r") or die("Unable to open config file: " . $configPath);
    $contents = fread($file, filesize($configPath));
    fclose($file); 
    if ($className === null)
        return json_decode($contents, true);
    return (array) new ConfigClass($contents, $className);
}
