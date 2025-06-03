<?php
require_once(__DIR__ . '/../converters/index.php');

use app\Application;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use \JSONObject as JSONObject;


/**
 * @SuppressWarnings(PHPMD.MissingImport)
 */
class Mail extends CityAPI {
    public bool $withXls = false;

    /**
     * @SuppressWarnings(PHPMD.ShortVariable)
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    function send(Application $application) {
        parent::checkApplication($application);

        $to = "szymon.nieradka@gmail.com";
        if (isProd()) {
            $to = $application->guessSMData()->email;
        }

        $transport = Transport::fromDsn(SMTP_GMAIL . '://' . SMTP_USER . ':' . rawurlencode(SMTP_PASS) . '@' . SMTP_HOST);
        
        $mailer = new Mailer($transport);

        $subject = $application->getEmailSubject();

        $message = (new Email());
        $message->from(new Address(EMAIL_SENDER, 'uprzejmiedonosze.net'));
        $message->to($to);
        $message->subject($subject);
        $message->cc(new Address($application->email, $application->user->name));
        $message->text(parent::formatEmail($application, true));
        $message->sender($application->email);
        $message->replyTo(new Address($application->email, $application->user->name));
        $message->returnPath($application->email);

        $message->getHeaders()->addTextHeader("X-UD-AppId", $application->id);
        $message->getHeaders()->addTextHeader("X-UD-UserId", $application->getUserNumber());
        $message->getHeaders()->addTextHeader("X-UD-AppNumber", $application->getNumber());
        $message->getHeaders()->addTextHeader("X-Entity-Ref-ID", $application->id);
        $message->getHeaders()->addTextHeader("References", $application->id . "@dka.email");
        $message->getHeaders()->addTextHeader('content-transfer-encoding', 'quoted-printable');

        $messageId = $message->getHeaders()->get('Message-ID');

        try {
            \semaphore\acquire($application->id, "sendMail");
            $application = \app\get($application->id); // get the latest version of the application

            $application->setStatus('confirmed-waiting');
            $application->addComment("admin", "Wysłano na adres {$application->guessSMData()->getName()} ($to).");
            $application->sent = new JSONObject();
            $application->sent->date = date(DT_FORMAT);
            $application->sent->subject = $subject;
            $application->sent->to = $to;
            $application->sent->cc = "{$application->user->name} ({$application->email})";
            $application->sent->from = "uprzejmiedonosze.net (" . EMAIL_SENDER . ")";
            $application->sent->body = parent::formatEmail($application, false);
            $application->sent->messageId = $messageId;
            $application->sent->method = "Mail";
            \app\save($application);

            [$fileatt, $fileattname] = \app\toPdf($application);
            $message->attachFromPath($fileatt, $fileattname);
            [$fileatt, $fileattname] = \app\toZip($application);
            $message->attachFromPath($fileatt, $fileattname);

            if (!isDev())
                $mailer->send($message);

        } catch (TransportExceptionInterface $error) {
            $application->setStatus('sending-failed', true);
            unset($application->sent);
            \app\save($application);
            throw new Exception($error->getMessage(), 500, $error);
        } finally {
            \semaphore\release($application->id, "sendMail");
            \app\rmPdf($application);
            \app\rmZip($application);
        }

        return $application;
    }
}
