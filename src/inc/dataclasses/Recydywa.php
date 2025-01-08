<?PHP namespace recydywa;

require_once(__DIR__ . '/JSONObject.php');

class Recydywa extends \JSONObject {
    protected const USE_ARRAY_FLOW = true;

    public int $appsCnt = 1;
    public int $usersCnt = 1;

    public static function withApps(array $apps): Recydywa {
        $instance = new self();
        $instance->appsCnt = count($apps);
        $instance->usersCnt = count(array_unique(array_map(fn ($app): string => $app->email, $apps)));
        return $instance;
    }

    public static function withValues(int $appsCnt, int $usersCnt): Recydywa {
        $instance = new self();
        $instance->appsCnt = $appsCnt;
        $instance->usersCnt = $usersCnt;
        return $instance;
    }
}