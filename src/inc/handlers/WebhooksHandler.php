<?PHP

require_once(__DIR__ . '/AbstractHandler.php');

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpForbiddenException;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;

class WebhooksHandler extends AbstractHandler {
    private static HttpFoundationFactory $httpFoundationFactory;

    public function __construct() {
        WebhooksHandler::$httpFoundationFactory = new HttpFoundationFactory();
    }
    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function mailgun(Request $request, Response $response): Response {        
        $event = $request->getParsedBody();
        $id = $event['event-data']['id'] ?? null;
        if (!$id) {
            logger($event, true);
            throw new HttpForbiddenException($request, 'Missing event id.');
        }

        \webhook\add($id, $event);
        $this->verify($request);
        
        $payload = $event['event-data'];
        $appId = $payload['user-variables']['appid'];
        $userNumber = $payload['user-variables']['userid'];
        $recipient = $payload['recipient'];
        
        if($payload['user-variables']['isprod'] !== "1") {
            \webhook\mark($id);
            return $this->renderJson($response, array(
                "type" => "non-prod",
                "status" => "ignored"
            ));
        }

        if(isset($payload['user-variables']['nofitication'])) {
            // this is a notification triggered by an email sent by this webhook
            // so I have to ignore it not to trigger an endless loop
            \webhook\mark($id);
            return $this->renderJson($response, array(
                "type" => "notification",
                "status" => "ignored"
            ));
        }
        $mailEvent = new MailEvent($payload);

        $semaphore = \semaphore\acquire(intval($userNumber));
        $application = \app\get($appId);

        if (!$application->wasSent()) {
            logger("mailgun webhook error, Application $appId was not sent!", true);
        }

        $comment = $mailEvent->formatComment();
        if ($comment) $application->addComment("mailer", $comment, $mailEvent->status);
        $ccToUser = $application->email == $recipient;

        if ($recipient == MAILER_FROM) {
            // this is BCC to Uprzejmie Donoszę, ignore it
            \webhook\mark($id);
            \semaphore\release($semaphore);
            return $this->renderJson($response, array(
                "type" => "notification",
                "status" => "ignored"
            ));
        }

        if (!$ccToUser) {
            // set sent status to accepted only if empty
            if ($mailEvent->status == 'accepted' && $application->status == 'confirmed')
                $application->setStatus('sending', true);
            if ($mailEvent->status == 'problem')
                $application->setStatus('sending-problem', true);
            if ($mailEvent->status == 'failed')
                $application->setStatus('sending-failed', true);
            if ($mailEvent->status == 'delivered')
                $application->setStatus('confirmed-waiting', true);
        }

        $application = \app\save($application);
        \webhook\mark($id);
        \semaphore\release($semaphore);

        if ($mailEvent->status == 'failed' && !$ccToUser)
            (new MailGun())->notifyUser($application,
                "Nie udało się nam dostarczyć wiadomości zgłoszenia {$application->getNumber()}",
                $mailEvent->getReason(),
                $recipient);

        return $this->renderJson($response, array(
            "status" => "OK"
        ));
    }

    private function verify(Request $psrRequest): void {
        $request = WebhooksHandler::$httpFoundationFactory->createRequest($psrRequest);

        $content = $request->toArray();
        if (
            !isset($content['signature']['timestamp'])
            || !isset($content['signature']['token'])
            || !isset($content['signature']['signature'])
            || !isset($content['event-data']['event'])
        ) {
            throw new HttpForbiddenException($psrRequest, 'Payload is malformed.');
        }
        if (
            !isset($content['event-data']['user-variables']['appid'])
        ) {
            throw new HttpForbiddenException($psrRequest, 'Missing app-id');
        }

        $this->validateSignature($content['signature']);
    }

    private function validateSignature(array $signature): void {
        // see https://documentation.mailgun.com/en/latest/user_manual.html#webhooks-1
        if (!hash_equals($signature['signature'], hash_hmac('sha256', $signature['timestamp'].$signature['token'], MAILER_WEBHOOK_SECRET))) {
            throw new RejectWebhookException('Signature is wrong.');
        }
    }
}

class MailEvent { // MailgunPayloadConverter
    public string $name;
    public string $id;
    public DateTimeImmutable $date;
    public string $reason;
    public string $recipient;
    public array $variables;
    public array $tags;
    public string $status;

    public function __construct(array $payload) {
        $this->id = $payload['id'];
        $this->reason = $this->parsetReason($payload);
        $this->name = $payload['event'];
        $this->date = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $payload['timestamp']));
        $this->recipient = $payload['recipient'];
        $this->variables = $payload['user-variables'] ?? [];
        $this->tags = $payload['tags'] ?? [];

        $this->status = match ($this->name) {
            'accepted' => 'accepted',
            'rejected' => 'failed',
            'delivered' => 'delivered',
            'blocked' => 'failed',
            'failed' => 'failed',
            'clicked' => 'delivered',
            'unsubscribed' => 'delivered',
            'opened' => 'delivered',
            'complained' => 'failed'
        };
        if ('temporary' === ($payload['severity'] ?? null)) {
            $this->status = 'problem';
        }

        logger("MailEvent {$this->name} <{$this->recipient}>");
    }

    public function formatComment(): ?string {
        $status = EMAIL_STATUS[$this->status] ?? null;
        if(!$status) return null;

        $reason = '';
        if ($this->reason) $reason = " ($this->reason)";

        return "$status do {$this->recipient}$reason";
    }

    public function getReason(): string {
        return $this->reason;
    }

    private function parsetReason(array $payload): string {
        if ('' !== ($payload['delivery-status']['description'] ?? '')) {
            return $payload['delivery-status']['description'];
        }
        if ('' !== ($payload['delivery-status']['message'] ?? '')) {
            return $payload['delivery-status']['message'];
        }
        if ('' !== ($payload['reason'] ?? '')) {
            return $payload['reason'];
        }

        return '';
    }
}
