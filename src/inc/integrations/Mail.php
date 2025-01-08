<?php
require_once(__DIR__ . '/../converters/index.php');

use app\Application;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use \JSONObject as JSONObject;
use Symfony\Component\Mime\Part\DataPart;

/**
 * @SuppressWarnings(PHPMD.MissingImport)
 */
class Mail extends CityAPI {
    public bool $withXls = false;

    /**
     * @SuppressWarnings(PHPMD.ShortVariable)
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    function send(Application &$application){
        parent::checkApplication($application);

        $to = "szymon.nieradka@gmail.com";
        if(isProd()){
            $to = $application->guessSMData()->email;
        }
         
        $transport = Transport::fromDsn(SMTP_GMAIL . '://' . SMTP_USER . ':' . SMTP_PASS . '@' . SMTP_HOST . ':' . SMTP_PORT);
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
        $message->getHeaders()->addTextHeader('content-transfer-encoding', 'quoted-printable');

        $messageId = $message->getHeaders()->get('Message-ID');

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

        try {
            [$fileatt, $fileattname] = \app\toPdf($application);
            $message->attachFromPath($fileatt, $fileattname);
            [$fileatt, $fileattname] = \app\toZip($application);
            $message->attachFromPath($fileatt, $fileattname);

            if ($this->withXls) {
                $message->addPart(new DataPart(
                    \app\app2Xls($application),
                    $application->getAppFilename('.xls'),
                    "application/vnd.ms-excel"
                ));
            }

            if (!isDev())
                $mailer->send($message);
        } catch (TransportExceptionInterface $error) {
            $application->setStatus('sending-failed', true);
            unset($application->sent);
            \app\save($application);
            throw new Exception($error, 500);
        } finally {
            \app\rmPdf($application);
            \app\rmZip($application);
        }

        \app\save($application);
        return $application;
    }
}

?>