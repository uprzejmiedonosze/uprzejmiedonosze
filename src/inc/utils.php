<?PHP

require_once(__DIR__ . '/dataclasses/Exceptions.php');

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

function fixRomanNumerals(string $input): string {
    return preg_replace_callback('/\s((XC|XL|L?X{0,3})(IX|IV|V?I{0,3}))\s/i', function ($matches) {
        return mb_strtoupper(
            $matches[0], 'UTF-8');
        }, $input
    );
}

function capitalizeSentence(string $input): string{
    if(!isset($input) || trim($input) === ''){
        return '';
    }
    $isUpperCase = (mb_strlen($input, 'UTF-8') / 2) < (int)preg_match_all('/[A-Z]/', $input);
    
    $out = trim(
        preg_replace_callback('/(?<!tj|np|tzw|tzn|pkt|itd|jw|m.in|ok|zob|bm|br|gr|szt|kl|ww)([.!?])\s+([[:lower:]])/', function ($matches) {
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


/**
 * See js: function num(value, numerals)
 */
function num(int $value, array $numerals) {
    $t0 = $value % 10;
    $t1 = $value % 100;
    $vo = [];
    $vo[] = $value;
    if ($value === 1 && $numerals[1])
        $vo[] = $numerals[1];
    else if (($value == 0 || ($t0 >= 0 && $t0 <= 1) || ($t0 >= 5 && $t0 <= 9) || ($t1 > 10 && $t1 < 20)) && $numerals[0])
        $vo[] = $numerals[0];
    else if ((($t1 < 10 || $t1 > 20) && $t0 >= 2 && $t0 <= 4) && $numerals[2])
        $vo[] = $numerals[2];
    return join(" ", $vo);
}

function getDomainFromEmail(string $email) {
    $email = trim($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return null;
    return strtolower(substr(strrchr($email, "@"), 1));
}

function domainUsesGoogleMail(string $domain) {
    if (!checkdnsrr($domain, "MX")) return false;
    $mxhosts = [];
    @getmxrr($domain, $mxhosts);
    foreach ($mxhosts as $host) {
        $host = strtolower(rtrim($host, '.'));
        if (strpos($host, '.google.com') !== false) return true;
    }

    // Optional: check TXT/SPF for include:_spf.google.com
    $txts = dns_get_record($domain, DNS_TXT);
    foreach ($txts as $t) {
        if (isset($t['txt']) && stripos($t['txt'], 'include:_spf.google.com') !== false) {
            return true;
        }
    }

    return false;
}