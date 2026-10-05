<?php

namespace UprzejmieDonosze\Tests\API;

// Kolejność ma znaczenie: API.php (przez SessionApiHandler) robi zwykły `require` alpr.php → plateRecognizer.php, więc musi
// być załadowane PRZED Vision.php (które robi require_once tego samego pliku) – inaczej „Cannot redeclare alpr\vehicleArea()”.
require_once __DIR__ . '/../../export/inc/handlers/SessionApiHandler.php';
require_once __DIR__ . '/../../export/inc/integrations/Vision.php';

use PHPUnit\Framework\TestCase;

/** Upload zdjęcia auta po analizie mobilnej nie woła ALPR drugi raz: wynik z cache jest skalowany do zapisanego pliku. */
class AlprReuseTest extends TestCase
{
    public function testScalesPlateAndVehicleBoxes(): void
    {
        $resp = ['results' => [[
            'plate' => 'ZS12345',
            'box' => ['xmin' => 100, 'ymin' => 200, 'xmax' => 300, 'ymax' => 260],
            'vehicle' => ['box' => ['xmin' => 10, 'ymin' => 20, 'xmax' => 1000, 'ymax' => 800]],
        ]], 'filename' => 'x.jpg'];

        $out = \alpr\scalePlateRecognizerResult($resp, 0.5, 0.5);

        $this->assertSame(['xmin' => 50, 'ymin' => 100, 'xmax' => 150, 'ymax' => 130], $out['results'][0]['box']);
        $this->assertSame(['xmin' => 5, 'ymin' => 10, 'xmax' => 500, 'ymax' => 400], $out['results'][0]['vehicle']['box']);
        $this->assertSame('ZS12345', $out['results'][0]['plate']);
        $this->assertSame('x.jpg', $out['filename']);
    }

    public function testResultWithoutVehicleBoxStaysValid(): void
    {
        $out = \alpr\scalePlateRecognizerResult(['results' => [['plate' => 'AB1', 'box' => ['xmin' => 2, 'ymin' => 4, 'xmax' => 6, 'ymax' => 8]]]], 2.0, 2.0);
        $this->assertSame(['xmin' => 4, 'ymin' => 8, 'xmax' => 12, 'ymax' => 16], $out['results'][0]['box']);
        $this->assertArrayNotHasKey('vehicle', $out['results'][0]);
        $this->assertSame([], \alpr\scalePlateRecognizerResult([], 2.0, 2.0));
    }
}
