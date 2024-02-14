<?PHP

/*
Description: The point-in-polygon algorithm allows you to check if a point is
inside a polygon or outside of it.
Author: Michaël Niessen (2009)
Website: http://AssemblySys.com

If you find this script useful, you can show your
appreciation by getting Michaël a cup of coffee ;)
https://ko-fi.com/assemblysys
*/

class PoliceStationAreas {
    private $polygons = Array();
    
    public function __construct() {
        $policeStations = file_get_contents(__DIR__ . "/../../public/api/config/police-stations.pjson");
        $this->polygons = unserialize($policeStations);
    }

    public function guess(float $lat, float $lng): string|null {
        foreach($this->polygons as $name => $polygon) {
            if ($this->pointInPolygon($lat, $lng, $polygon))
                return $name;
        }
        return null;
    }


    private function pointInPolygon(float $lat, float $lng, array $vertices): bool {
        $point = array("x" => $lng, "y" => $lat);
        // Check if the point is inside the polygon or on the boundary
        $intersections = 0;
        $verticesCount = count($vertices);

        for ($i=1; $i < $verticesCount; $i++) {
            $vertex1 = $vertices[$i-1];
            $vertex2 = $vertices[$i];
            // Check if point is on an horizontal polygon boundary
            if ($vertex1['y'] == $vertex2['y']
                && $vertex1['y'] == $point['y']
                && $point['x'] > min($vertex1['x'], $vertex2['x'])
                && $point['x'] < max($vertex1['x'], $vertex2['x']))
                return true; // "boundary";

            // Check if point is on the polygon boundary (other than horizontal)
            if ($point['y'] > min($vertex1['y'], $vertex2['y'])
                && $point['y'] <= max($vertex1['y'], $vertex2['y'])
                && $point['x'] <= max($vertex1['x'], $vertex2['x'])
                && $vertex1['y'] != $vertex2['y']) {
                $xinters = ($point['y'] - $vertex1['y']) * ($vertex2['x'] - $vertex1['x']) / ($vertex2['y'] - $vertex1['y']) + $vertex1['x'];
                
                if ($xinters == $point['x'])
                    return true; // "boundary";

                if ($vertex1['x'] == $vertex2['x'] || $point['x'] <= $xinters)
                    $intersections++;
            }
        }
        // If the number of edges we passed through is odd, then it's in the polygon.
        return $intersections % 2 != 0;
    }
}
