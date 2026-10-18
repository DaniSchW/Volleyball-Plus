<?php

namespace App\Support\Mail;

use Closure;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MessageConverter;

/**
 * Sends mail through PHP's built-in mail() function – the default on
 * shared hosting, where it is usually the only preconfigured option.
 * SMTP stays available as an alternative mailer.
 */
class PhpMailTransport extends AbstractTransport
{
    /** @var Closure(string, string, string, string, string): bool */
    private Closure $sender;

    /**
     * @param  (Closure(string, string, string, string, string): bool)|null  $sender  replaces mail() in tests
     */
    public function __construct(?Closure $sender = null)
    {
        parent::__construct();

        $this->sender = $sender ?? static fn (string $to, string $subject, string $body, string $headers, string $params): bool => mail($to, $subject, $body, $headers, $params);
    }

    protected function doSend(SentMessage $message): void
    {
        $original = $message->getOriginalMessage();
        $email = $original instanceof Message ? $original : MessageConverter::toEmail($original);

        $headers = $email->getPreparedHeaders();
        $body = $email->getBody();

        $to = $headers->has('To') ? $headers->get('To')->getBodyAsString() : '';
        $subject = $headers->has('Subject') ? $headers->get('Subject')->getBodyAsString() : '';
        $headers->remove('To');
        $headers->remove('Subject');

        foreach ($body->getPreparedHeaders()->all() as $header) {
            $headers->add($header);
        }

        $sender = $message->getEnvelope()->getSender()->getAddress();
        $params = filter_var($sender, FILTER_VALIDATE_EMAIL) && ! preg_match('/\s/', $sender) ? '-f'.$sender : '';

        $sent = ($this->sender)($to, $subject, $body->bodyToString(), rtrim($headers->toString()), $params);

        if (! $sent) {
            throw new TransportException('mail() refused to send the message.');
        }
    }

    public function __toString(): string
    {
        return 'phpmail://default';
    }
}
