<?php

namespace generator;

require_once(__DIR__ . '/AbstractHandler.php');
require_once(__DIR__ . '/../data.php');

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ApiAiHandler extends \AbstractHandler {
    //private string $model = 'gpt-3.5-turbo'; //'gpt-5-nano'; // 'gpt-5-mini':
    private string $model = 'gpt-5-mini';
    private string $project = \OPENAI_PROJECT;

    protected function jsonResponse(Response $response, $data = null, int $status = 200): Response {
        $payload = [
            'status' => $status >= 200 && $status < 300 ? 'success' : 'error',
            'data' => $data
        ];

        return $this->renderJson($response, $payload, $status);
    }

    private function printAndFlush(string $text): void {
        echo "data: $text\n\n";
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }


    public function stream(Request $request, Response $response, array $args): Response {
        // Get data from either POST body or GET parameters
        if ($request->getMethod() === 'GET') {
            $queryParams = $request->getQueryParams();
            $data = [
                'topics' => json_decode($queryParams['topics'] ?? '[]', true),
                'form_type' => $queryParams['form_type'] ?? '',
                'target' => $queryParams['target'] ?? '',
                'recipient' => $queryParams['recipient'] ?? ''
            ];
        } else {
            $data = $request->getParsedBody();
        }

        // Validate input
        $required = ['topics', 'form_type', 'target'];
        $missing = [];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                $missing[] = $field;
            }
        }

        if (!empty($missing)) {
            return $this->jsonResponse($response, [
                'error' => 'Missing required fields',
                'missing' => $missing
            ], 400);
        }

        // $data['recipient'] may contain a more specific recipient than $data['target']
        if (!empty($data['recipient'])) {
          $mps = \json\get('parlamentary.json');
          if (!isset($mps[$data['recipient']])) {
            return $this->jsonResponse($response, [
                'error' => 'Incorrent parameters passed'
            ], 400);
          };
        }

        $user = $request->getAttribute('user');

        try {
            $topics = (array)$data['topics'];
            $formType = $data['form_type'];
            $target = $data['target'];
            $recipient = $data['recipient'];

            $petition = Petition::withData($topics, $formType, $target, $recipient, $user);

            $systemPrompt = $petition->generateSystemPrompt();
            $contentPrompt = $petition->generateContentPrompt();
            \generator\set($petition);

            $client = \OpenAI::client(apiKey: OPENAI_API_KEY, project: $this->project);

            $stream = $client->chat()->createStreamed([
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $contentPrompt]
                ],
                'stream' => true
            ]);

            // Disable output buffering
            if (ob_get_level() > 0) {
                ob_end_flush();
            }

            // Create a custom stream handler
            $streamHandler = function () use ($stream, $petition, $systemPrompt, $contentPrompt, $user) {
                if (ob_get_level() > 0) {
                    ob_end_clean();
                }

                // Set headers for streaming
                header('Content-Type: text/event-stream');
                header('Cache-Control: no-cache');
                header('X-Accel-Buffering: no');
                header('Connection: keep-alive');

                $completion = '';

                // Process the stream
                foreach ($stream as $chunk) {
                    $content = $chunk->choices[0]->delta->content ?? '';
                    if (!empty($content)) {
                        $completion .= $content;
                        $this->printAndFlush(json_encode(['content' => $content]));
                    }
                }
                $name = $user->data->name;
                $city = $user->data->address;

                $this->printAndFlush(json_encode(['content' => "\n"]));
                $this->printAndFlush(json_encode(['content' => "\n$name"]));
                $this->printAndFlush(json_encode(['content' => "\n$city\n"]));

                // Send done signal
                $this->printAndFlush('[DONE]');

                $price = $this->calculateEstimation($systemPrompt, $contentPrompt, $completion);
                $petition->setGenerated($completion, $price);
                \generator\set($petition);

                exit;
            };

            // Execute the stream handler
            $streamHandler();

            // This return is a fallback in case output buffering is disabled
            return $response->withHeader('Content-Type', 'text/plain')
                ->withStatus(200);
        } catch (\Exception $e) {
            return $this->jsonResponse($response, [
                'error' => 'Failed to stream content',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    private function __calculatePrice(object $usage): float {
        global $MODEL_PRICING;

        $pricing = $MODEL_PRICING[$this->model];
        $inputTokens = $usage->promptTokens;
        $outputTokens = $usage->completionTokens;

        return ($inputTokens * $pricing['prompt'] + $outputTokens * $pricing['completion']) / 1_000_000;
    }

    private function calculateEstimation(string $systemPrompt, string $contentPrompt, string $response): float {
        global $MODEL_PRICING;

        function count_tokens(string $text): int {
            return mb_strlen($text, 'UTF-8') / 3 * 2;
        }

        $pricing = $MODEL_PRICING[$this->model];
        $inputTokens = count_tokens($systemPrompt) + count_tokens($contentPrompt);
        $outputTokens = count_tokens($response);

        return ($inputTokens * $pricing['prompt'] + $outputTokens * $pricing['completion']) / 1_000_000;
    }


    /**
     * Get available topics
     */
    public function getTopics(Request $request, Response $response, array $args): Response {
        global $TOPICS;
        return $this->renderJson($response, $TOPICS);
    }

    /**
     * Get available forms
     */
    public function getForms(Request $request, Response $response, array $args): Response {
        global $FORMS;
        return $this->renderJson($response, $FORMS);
    }

    /**
     * Get available targets
     */
    public function getTargets(Request $request, Response $response, array $args): Response {
        global $TARGETS;
        return $this->renderJson($response, $TARGETS);
    }

    private static function uniqueCities(array $districts): array {
        $cities = [];
        foreach ($districts as $district) {
            $cities = array_merge($cities, $district['cities']);
        }
        return array_unique($cities);
    }

    private static function changeNameOrder(string $key): string {
        $parts = explode(' ', trim($key));
        $count = count($parts);
        switch ($count) {
            case 2:
                return $parts[1] . ' ' . $parts[0];
            case 3:
                return $parts[1] . ' ' . $parts[2] . ' ' . $parts[0];
            case 4:
                return $parts[3] . ' ' . $parts[0] . ' ' . $parts[1] . ' ' . $parts[2];
            default:
                return $key;
        }
    }

    private static function getUserVoivodeship(\user\User $user) {
        if (!$user) return null;
        $lastLocation = $user->getLastLocation();
        if (!$lastLocation) return null;
        $latlng = explode(',', $lastLocation);
        $geoData = \geo\Nominatim($latlng[0], $latlng[1]);
        $address = $geoData['address'];
        if (array_key_exists('voivodeship', $address))
            return trim($address['voivodeship']);
        return null;
    }

    private static function getUserDistrict(\user\User $user, array $districts) {
        if (!$user) return null;

        $unqueCities = self::uniqueCities($districts);

        $address = $user->data->address;
        if ($address) {
            $split = explode(',', $address);
            $city = trim(end($split));
            if (in_array($city, $unqueCities)) return $city;
        }
        $lastLocation = $user->getLastLocation();
        if ($lastLocation) {
            $latlng = explode(',', $lastLocation);
            $geoData = \geo\Nominatim($latlng[0], $latlng[1]);
            $address = $geoData['address'];
            if (array_key_exists('city', $address)) {
                $city = trim($address['city']);
                if (in_array($city, $unqueCities)) return $city;
            }

            if (array_key_exists('municipality', $address)) {
                $municipality = $address['municipality'];
                if (in_array($municipality, $unqueCities)) return $municipality;
            }
        }
        return null;
    }

    public function getSuggestedParlamentary(Request $request, Response $response, array $args): Response {
        $mps = \json\get('parlamentary.json');
        $districts = \json\get('electoral-districts.json');
        $user = $request->getAttribute('user');
        $city = null;
        if ($user) {
            $city = self::getUserDistrict($user, $districts);
            $voivodeship = self::getUserVoivodeship($user);
        }
        foreach ($mps as $key => $mp) {
            $cities = $districts[$mp['district']]['cities'];

            if (!array_key_exists('committees', $mps[$key])) { # || !array_key_exists('INF', $mps[$key]['committees'])) {
                unset($mps[$key]);
                continue;
            }

            $mps[$key]['district_match'] = null;
            if ($city)
                $mps[$key]['district_match'] = in_array($city, $cities);
            $mps[$key]['vovoideship_match'] = null;
            if ($voivodeship)
                $mps[$key]['vovoideship_match'] = $districts[$mp['district']]['voivodeship'] == $voivodeship;
            $mps[$key]['name'] = $this->changeNameOrder($key);
            $mps[$key]['sex'] = \user\User::_guessSex($mps[$key]['name']);

            $mps[$key]['formal'] = "Szanowna Pani\n{$mps[$key]['name']}\nPosłanka na Sejm";
            if ($mps[$key]['sex'] == 'm')
                $mps[$key]['formal'] = "Szanowy Pan\n{$mps[$key]['name']}\nPoseł na Sejm";

            
        }

        return $this->renderJson($response, $mps);
    }

    public function getParlamentary(Request $request, Response $response, array $args): Response {
        $mps = \json\get('parlamentary.json');
        $districts = \json\get('electoral-districts.json');

        $params = $request->getQueryParams();
        $voivodeship = $this->getParam($params, 'v', 0);
        $city = $this->getParam($params, 'c', 0);

        $filterByUser = $this->getParam($params, 'u', 0);
        $user = $request->getAttribute('user');
        if ($filterByUser != 0 && $user) {
            $city = self::getUserDistrict($user, $districts);
        }

        foreach ($mps as $key => $mp) {
            $mps[$key]['cities'] = $districts[$mp['district']]['cities'];
            $mps[$key]['voivodeship'] = $districts[$mp['district']]['voivodeship'];

            if ($voivodeship > 0 && $mps[$key]['voivodeship'] != $voivodeship) {
                unset($mps[$key]);
                continue;
            }
            if ($city > 0 && !in_array($city, $mps[$key]['cities'])) {
                unset($mps[$key]);
                continue;
            }
        }

        return $this->renderJson($response, $mps);
    }
}
