<?php

namespace UprzejmieDonosze\Tests\Mcp;

require_once __DIR__ . '/../../export/inc/integrations/Geolocation.php';

use PHPUnit\Framework\TestCase;

/**
 * Verifies the server-side EXIF GPS reader used by create_report_draft when
 * lat/lng are omitted. It must mirror the web client's ExifReader: GPS is read
 * from the raw upload bytes, because the image pipeline (GD) strips EXIF on
 * re-encode.
 */
class ExifGpsTest extends TestCase
{
    public function testReadsGpsFromJpegExif(): void
    {
        if (!function_exists('exif_read_data')) {
            self::markTestSkipped('PHP exif extension not available');
        }

        $gps = \geo\exifGps($this->jpegWithExifGps(51.12158, 16.99407));

        self::assertIsArray($gps);
        self::assertEqualsWithDelta(51.12158, $gps[0], 0.001);
        self::assertEqualsWithDelta(16.99407, $gps[1], 0.001);
    }

    public function testReturnsNullForJpegWithoutExif(): void
    {
        if (!function_exists('exif_read_data')) {
            self::markTestSkipped('PHP exif extension not available');
        }

        $img = imagecreatetruecolor(8, 8);
        ob_start();
        imagejpeg($img, null, 90);
        $jpeg = ob_get_clean();
        imagedestroy($img);

        self::assertNull(\geo\exifGps($jpeg));
    }

    public function testReturnsNullForPng(): void
    {
        if (!function_exists('exif_read_data')) {
            self::markTestSkipped('PHP exif extension not available');
        }

        $img = imagecreatetruecolor(8, 8);
        ob_start();
        imagepng($img);
        $png = ob_get_clean();
        imagedestroy($img);

        self::assertNull(\geo\exifGps($png));
    }

    private function jpegWithExifGps(float $lat, float $lng): string
    {
        $img = imagecreatetruecolor(8, 8);
        ob_start();
        imagejpeg($img, null, 90);
        $jpeg = ob_get_clean();
        imagedestroy($img);

        return "\xFF\xD8" . $this->exifApp1($lat, $lng) . substr($jpeg, 2);
    }

    private function exifApp1(float $lat, float $lng): string
    {
        $latParts = $this->rationalize($lat);
        $lngParts = $this->rationalize($lng);

        // GPS IFD at offset 26 of the TIFF block: 8-byte header + 18-byte IFD0.
        $gpsIfd = pack('v', 4) // entry count
            . $this->exifEntry(0x0001, 2, 2, "N\x00\x00\x00")    // GPSLatitudeRef
            . $this->exifEntry(0x0002, 5, 3, pack('V', 80))      // GPSLatitude → 3 rationals at 80
            . $this->exifEntry(0x0003, 2, 2, "E\x00\x00\x00")    // GPSLongitudeRef
            . $this->exifEntry(0x0004, 5, 3, pack('V', 104))     // GPSLongitude → 3 rationals at 104
            . pack('V', 0);                                      // next IFD pointer

        $rationals = '';
        foreach ($latParts as [$num, $den]) {
            $rationals .= pack('VV', $num, $den);
        }
        foreach ($lngParts as [$num, $den]) {
            $rationals .= pack('VV', $num, $den);
        }

        // IFD0 at offset 8: a single GPSInfo pointer (tag 0x8825) to offset 26.
        $ifd0 = pack('v', 1)
            . $this->exifEntry(0x8825, 4, 1, pack('V', 26))
            . pack('V', 0);

        $tiff = 'II' . pack('v', 42) . pack('V', 8) . $ifd0 . $gpsIfd . $rationals;
        $app1 = "Exif\x00\x00" . $tiff;
        // JPEG marker lengths are big-endian (the TIFF block itself is 'II' LE).
        return "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1;
    }

    /** A 4-byte IFD entry value (all values used here fit inline or are offsets). */
    private function exifEntry(int $tag, int $type, int $count, string $value): string
    {
        return pack('vvV', $tag, $type, $count) . $value;
    }

    /** D°M'S as unsigned 32-bit rationals. */
    private function rationalize(float $value): array
    {
        $deg = (int) floor($value);
        $min = (int) floor(($value - $deg) * 60);
        $sec = ($value - $deg - $min / 60) * 3600;
        $secNum = (int) round($sec * 1000);
        return [[$deg, 1], [$min, 1], [$secNum, 1000]];
    }
}
