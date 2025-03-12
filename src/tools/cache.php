<?PHP

require_once(__DIR__ . '/../../vendor/autoload.php');

main($argc, $argv);

function main(int $argc, array $argv): void {
    if ($argc < 2) {
        echo "Usage:\n";
        echo "  php .../cache.php <search term>\n";
        echo "  php .../cache.php del <entry key>\n";
        exit(1);
    }

    if ($argv[1] === 'del' && isset($argv[2]))
        exit(delete($argv[2]));

    exit(search($argv[1]));
}

function delete(string $key): int {
    $cache = new Memcache;
    $cache->connect('localhost', 11211);

    if ($cache->delete($key)) {
        echo "Deleted cache entry with key: $key\n";
        return 0;
    }
    echo "Failed to delete cache entry with key: $key\n";
    return 1;
}

function search(string $term): int {
    $search = new \Qmegas\MemcacheSearch();
    $search->addServer('127.0.0.1', 11211);
    $cache = new Memcache;
    $cache->connect('localhost', 11211);

    $find = new \Qmegas\Finder\Inline($term);

    foreach ($search->search($find) as $item) {
        $key = $item->getKey();
        $value = (array)$cache->get($key);

        $formattedKey = str_pad($key, 40, ' ', STR_PAD_RIGHT);
        echo "\033[44;37m$formattedKey\033[0m\n";
        print_r($value);
        echo "\n";
        echo "\n";

    }
    return 0;
}

