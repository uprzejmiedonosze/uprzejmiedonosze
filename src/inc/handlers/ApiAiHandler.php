<?php
declare(strict_types=1);
require_once(__DIR__ . '/AbstractHandler.php');
require_once(__DIR__ . '/../data.php');

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ApiAiHandler extends AbstractHandler {
    private string $model = 'gpt-5-nano';

    protected function jsonResponse(Response $response, $data = null, int $status = 200): Response {
        $payload = [
            'status' => $status >= 200 && $status < 300 ? 'success' : 'error',
            'data' => $data
        ];

        return $this->renderJson($response, $payload, $status);
    }


    public function stream(Request $request, Response $response, array $args): Response {
        // Get data from either POST body or GET parameters
        if ($request->getMethod() === 'GET') {
            $queryParams = $request->getQueryParams();
            $data = [
                'topics' => json_decode($queryParams['topics'] ?? '[]', true),
                'form_type' => $queryParams['form_type'] ?? '',
                'target' => $queryParams['target'] ?? ''
            ];
        } else {
            $data = $request->getParsedBody();
        }
        logger($data, true);
        
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
        
        $user = $request->getAttribute('user');
        $isPatron = $user->isFormerPatron() || $user->isPatron() || $user->isAdmin();
        if (!$isPatron) {
            return $this->jsonResponse($response, [
                'error' => 'You must be a patron to use this feature',
                'missing' => ['patron']
            ], 403);
        }

        try {
            list($systemPrompt, $contentPrompt) = $this->getPrompt(
                (array)$data['topics'],
                $data['form_type'],
                $data['target'],
                $user->data->name,
                $user->data->address
            );

            
            // Ustawienie nagłówków dla długotrwałego połączenia
            header('X-Accel-Buffering: no');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('Keep-Alive: timeout=300, max=1000');
            
            $client = OpenAI::client(OPENAI_API_KEY);
            
            $stream = $client->chat()->createStreamed([
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $contentPrompt]
                ],
                'stream' => true
            ]);

            // Set headers for streaming
            $response = $response
                ->withHeader('Content-Type', 'text/event-stream; charset=utf-8')
                ->withHeader('Cache-Control', 'no-cache')
                ->withHeader('X-Accel-Buffering', 'no')
                ->withHeader('Connection', 'keep-alive');

            // Disable output buffering
            if (ob_get_level() > 0) {
                ob_end_flush();
            }
            
            // Create a custom stream handler
            $streamHandler = function() use ($stream, $response) {
                // Start output buffering
                if (ob_get_level() > 0) {
                    ob_end_clean();
                }
                
                // Set headers for streaming
                header('Content-Type: text/event-stream');
                header('Cache-Control: no-cache');
                header('X-Accel-Buffering: no');
                header('Connection: keep-alive');
                
                // Process the stream
                foreach ($stream as $chunk) {
                    $content = $chunk->choices[0]->delta->content ?? '';
                    if (!empty($content)) {
                        echo "data: " . json_encode(['content' => $content]) . "\n\n";
                        if (ob_get_level() > 0) {
                            ob_flush();
                        }
                        flush();
                    }
                }
                
                // Send done signal
                echo "data: [DONE]\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
                
                // End the script
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

    private function getPrompt(array $topics, string $formType, string $target, string $name, string $city): array {
        global $TOPICS, $TARGETS, $FORMS, $INTRO;

        $topicsStr = "";
        foreach ($topics as $topicId) {
            if (!isset($TOPICS[$topicId])) continue;
            
            $topic = $TOPICS[$topicId];
            $topicsStr .= "\n\n## {$topic['title']}\n{$topic['desc']}\n";
            
            if (!empty($topic['topics'])) {
                $topicsStr .= "  - " . implode("\n  - ", $topic['topics']);
            }

            if ($formType === 'proposal' && !empty($topic['law'])) {
                $topicsStr .= "\n### Propozycja zmiany przepisów:\n{$topic['law']}";
            }
        }

        $systemPrompt = "Jesteś mieszkańcem i obywatelem zirytowanym powszechnym łamaniem przepisów związanych z parkowaniem przez kierowców";
        $targetTitle = $TARGETS[$target]['title'] ?? $target;
        $intro = $formType !== 'email' ? "\n\n# Pozostałe\n\nMożesz użyć także tych materiałów:\n\n{$INTRO}" : "";

        $formText = $FORMS[$formType] ?? $formType;
        $contentPrompt = "# Zadanie\n\nNapisz $formText do $targetTitle w sprawie wadliwych przepisów regulujących parkowanie w Polsce.\n\n# Uwagi ogólne\n\nUżywaj przykładów i propozycji podanych poniżej. Nie wymyślaj własnych propozycji.\n\nNie stosuj formatowania markdown albo ikon. Czysty tekst.\n\nPodpisz dokument jako:\n$name\n$city\n\n# Szczegóły do poruszenia\n$topicsStr\n\n$intro\n\n# Uwagi końcowe\n\n- Pisz w pierwszej osobie liczby pojedynczej.\n- Pisz w stylu oficjalnym, ale nie przesadnie formalnym.\n- Pisz krótkie i konkretne zdania.\n- Używaj akapitów i wypunktowań.\n- Używaj podtytułów do podziału na sekcje.\n- Bądź zwięzły i na temat.\n";

        return [$systemPrompt, $contentPrompt];
    }

    private function calculatePrice(object $usage): float {
        global $MODEL_PRICING;

        $pricing = $MODEL_PRICING[$this->model];
        $inputTokens = $usage->promptTokens;
        $outputTokens = $usage->completionTokens;

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
}
