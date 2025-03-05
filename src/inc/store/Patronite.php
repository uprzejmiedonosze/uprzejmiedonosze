<?PHP namespace patronite;

use cache\Type;

function is(string $email): bool {
    $patrons = get();
    return $patrons[$email]['active'] ?? false;
}

function isFormer(string $email):bool {
    $patrons = get();
    return array_key_exists($email, $patrons) && !($patrons[$email]['active'] ?? false);
}

function active(bool $useCache=true): array {
    return array_filter(get(useCache:$useCache), fn($patron) => $patron['active'] && !($patron['duplicate'] ?? false));
}

function get(bool $useCache=true) {
    $patrons = \cache\get(Type::Patronite, "");
    if($useCache && $patrons){
        return $patrons;
    }
    $active = __get(PatronieStatus::ACTIVE);
    $inactive = __get(PatronieStatus::INACTIVE);
    $patrons = array_merge($active, $inactive);

    if (count($patrons) > 0)
        \cache\set(Type::Patronite, "", $patrons, 0, 24*60*60);
    return $patrons;
}

enum PatronieStatus:string {
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
}


function __get(PatronieStatus $status):array {
    if (!defined("\\PATRONITE_TOKEN")) return [];
    $result = \curl\request("https://patronite.pl/author-api/patrons/{$status->value}?with_notes=yes",
        [], "Patronite", array(
            "Authorization: token " . \PATRONITE_TOKEN,
            "Content-Type: application/json"
        ));
    if (!($result['results']??null)) return [];

    $output = array();
    foreach ($result['results'] as $patron) {
        $output[$patron["email"]] = array(
            "amount" => $patron["amount"],
            "note" => $patron["note"],
            "active" => (($patron["status"] ?? false) == 'aktywna')
        );
        if (!str_contains($patron["note"], "@")) continue;
        foreach(explode(",", $patron["note"]) as $email) {
            $output[$email] = array(
                "amount" => $patron["amount"],
                "note" => "imported from " . $patron["email"],
                "active" => (($patron["status"] ?? false) == 'aktywna'),
                "duplicate" => true
            );
        }
    }
    return $output;
}
