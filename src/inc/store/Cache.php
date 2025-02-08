<?php

namespace cache {
    $cache = new \Memcache;
    $cache->connect('localhost', 11211);

    enum Type {
        case OpenAlpr;
        case Platerecognizer;
        case AlprBudgetConsumed;

        case GoogleMaps;
        case Nominatim;
        case MapBox;

        case AppsByPlate;
        case GlobalStats;
        case UserStats;

        case Recydywa;
        case FirebaseKeys;
        case RecydywaStats;

        case Semaphore;

        case Patronite;
    }

    const _KEY_MAPPPING = array(
        'OpenAlpr' => '_alpr-',
        'Platerecognizer' => '_platerecognizer-',
        'Recydywa' => 'recydywa-',
        'Nominatim' => 'nominatim-v1 ',
        'GoogleMaps' => 'google-maps-v2 ',
        'MapBox' => 'mapbox-v1 ',
        'UserStats' => 'stats3-'
    );

    function key(Type $type, ?string $key): string {
        return  HOST . '-' . (_KEY_MAPPPING[$type->name] ?? $type->name) . ($key ?? '');
    }

    function get(Type $type, ?string $key=""): mixed {
        global $cache;
        return $cache->get(key($type, $key));
    }

    function set(Type $type, ?string $key, mixed $value, int $flag = 0, int $expire = 24 * 60 * 60): void {
        global $cache;
        $cache->set(key($type, $key), $value, $flag, $expire);
    }

    function add(Type $type, ?string $key, mixed $value, int $flag = 0, int $expire = 24 * 60 * 60): bool {
        global $cache;
        return $cache->add(key($type, $key), $value, $flag, $expire);
    }

    function delete(Type $type, ?string $key): void {
        global $cache;
        $cache->delete(key($type, $key));
    }
}

namespace cache\alpr {
    function get(\cache\Type $type, string $key): mixed {
        $result = \cache\get($type, $key);
        if($result){
            logger("get_alpr cache-hit $key");
            unset($result['credits_monthly_used']);
            unset($result['credits_monthly_total']);
            return $result;
        }
        logger("get_alpr cache-miss $key");
        return null;
    }

    function set(\cache\Type $type, string $key, $value) {
        \cache\set($type, $key, $value, MEMCACHE_COMPRESSED, 0);
    }
}

namespace cache\geo {
    function get(\cache\Type $type, string $key): array|bool {
        $result = \cache\get($type, $key);
        if ($result) logger("geo cache-hit $key");
        else logger("geo cache-miss $key");
        return $result;
    }

    function set(\cache\Type $type, string $key, array $value): void {
        \cache\set($type, $key, $value, MEMCACHE_COMPRESSED, 0);
    }
}
