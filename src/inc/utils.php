<?PHP
require_once(__DIR__ . '/config.php');
require_once(__DIR__ . '/dataclasses/Exceptions.php');
require_once(__DIR__ . '/Logger.php');

/**
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
function trimstr2upper($in) {
    return trim(mb_strtoupper($in, 'UTF-8'));
}

/**
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
function trimstr2lower($in) {
    return trim(mb_strtolower($in, 'UTF-8'));
}

function cleanWhiteChars($input) {
    return trim(preg_replace("/\s+/u", " ", $input));
}

function fixCapitalizedBrandNames(string $input): string {
    $acronyms = [
        "Bmw" => "BMW",
        "Mercedes-benz" => "Mercedes-Benz",
        "Alfa-romeo" => "Alfa-Romeo",
        "Land-rover" => "Land-Rover",
        "Fso" => "FSO",
        "Ssangyong" => "SsangYong"
    ];
    foreach ($acronyms as $key => $value) {
        $input = str_replace($key, $value, $input);
    }
    return $input;
}

function capitalizeSentence(string $input): string{
    if(!isset($input) || trim($input) === ''){
        return '';
    }
    $isUpperCase = (mb_strlen($input, 'UTF-8') / 2) < (int)preg_match_all('/[A-Z]/', $input);
    
    $out = trim(
        preg_replace_callback('/(?<!tj|np|tzw|tzn)([.!?])\s+([[:lower:]])/', function ($matches) {
            return mb_strtoupper($matches[1] . ' ' . $matches[2], 'UTF-8');
            }, ucfirst( $isUpperCase ? (mb_strtolower($input, 'UTF-8')): $input )
        )
    );
    $out = fixCapitalizedBrandNames($out);
    return (substr($out, -1) == '.')? $out: "{$out}.";
}

function capitalizeName($input){
    if(!isset($input) || cleanWhiteChars($input) === ''){
        return '';
    }
    return mb_convert_case(cleanWhiteChars($input), MB_CASE_TITLE, 'UTF-8');
}

function formatDateTime(string $jsonDate, string $pattern): string {
    $formatter = new IntlDateFormatter(locale: 'pl-PL', timezone: 'Europe/Warsaw', pattern: $pattern);
    return $formatter->format(new DateTime($jsonDate));
}

/**
 * @SuppressWarnings(PHPMD.Superglobals)
 */
function isIOS(){
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $iPod    = (bool)stripos($userAgent, "iPod");
    $iPhone  = (bool)stripos($userAgent, "iPhone");
    $iPad    = (bool)stripos($userAgent, "iPad");
    return $iPod || $iPhone || $iPad;
}

function extractAppNumer($appNumber) {
    $number = explode("/", $appNumber);
    return intval($number[2]);
}

function setSentryTag(string $tag, $value): void {
    if (!isProd()) return;

    \Sentry\configureScope(function (\Sentry\State\Scope $scope) use ($tag, $value): void {
        $scope->setTag($tag, $value);
    });
}

?>
