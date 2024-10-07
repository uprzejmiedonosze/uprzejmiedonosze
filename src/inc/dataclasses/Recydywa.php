<?PHP namespace recydywa;

require_once(__DIR__ . '/JSONObject.php');

class Recydywa extends \JSONObject {
    protected const USE_ARRAY_FLOW = true;

    public int $appsCnt = 1;
    public int $usersCnt = 1;
    public int $citiesCnt = 1;

    public static function withApps(array $apps): Recydywa {
        $instance = new self();
        $instance->appsCnt = count($apps);
        $instance->usersCnt = count(array_unique(array_map(fn ($app): string => $app->user->email, $apps)));
        $instance->citiesCnt = count(array_unique(array_map(fn ($app): string => $app->address->city, $apps)));
        return $instance;
    }

    public static function withValues(int $appsCnt, int $usersCnt, int $citiesCnt): Recydywa {
        $instance = new self();
        $instance->appsCnt = $appsCnt;
        $instance->usersCnt = $usersCnt;
        $instance->citiesCnt = $citiesCnt;
        return $instance;
    }
}