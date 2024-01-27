<?PHP


try {
    if(count($argv) !== 3)
        throw new Exception("usage:\n$ php {$argv[0]} police-stations.csv police-stations.json\n");
    transform($argv[1], $argv[2]);
} catch(Exception $e) {
    fwrite(STDERR, "{$e->getMessage()}\n");
    exit(-1);
}

/**
 * @SuppressWarnings(PHPMD.ShortVariable)
 */ 
function transform($polygonsFile, $policeStationsFile) {

    $policeStations = json_decode(file_get_contents($policeStationsFile), true);

    $polygons = Array();
    $file = fopen($polygonsFile, "r");
    while (([$definition, $name] = fgetcsv($file)) !== FALSE) {
        preg_match('/^([A-Z]+)\s\(\(?([\d,.\s]+)\)\)?/', $definition, $matches);
        if(count($matches) < 3) continue;
        if(!array_key_exists($name, $policeStations)) {
            throw new Exception("'$polygonsFile' file defines police station '$name' missing in '$policeStationsFile'!");
        }
        $polygon = explode(',', $matches[2]);
        // Transform string coordinates into arrays with x and y values
        $polygons[$name] = array_map(function ($point) {
            [$x, $y] = explode(" ", trim($point));
            return array("x" => (float) $x, "y" => (float) $y);
        }, $polygon);
    }
    fclose($file);
    echo serialize($polygons);
}
