<?PHP namespace json;

require_once(__DIR__ . '/../dataclasses/ConfigClass.php');
const CONFIG_DIR = __DIR__ . '/../../public/api/config';

use \ConfigClass;

function get(string $filename, ?string $className = null) {
    $configPath = CONFIG_DIR . '/' . $filename;
    $file = @fopen($configPath, "r");
    if ($file === false) {
        throw new \Exception("Nie udało się otworzyć pliku konfiguracyjnego", 500);
    }
    $contents = fread($file, filesize($configPath));
    fclose($file); 
    if ($className === null)
        return json_decode($contents, true);
    return (array) new ConfigClass($contents, $className);
}
