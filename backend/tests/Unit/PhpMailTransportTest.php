<?php

namespace Tests\Unit;

use App\Support\Mail\PhpMailTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;

class PhpMailTransportTest extends TestCase
{
    public function test_hands_the_message_to_mail(): void
    {
        $calls = [];
        $transport = new PhpMailTransport(function (string $to, string $subject, string $body, string $headers, string $params) use (&$calls): bool {
            $calls[] = compact('to', 'subject', 'body', 'headers', 'params');

            return true;
        });

        $transport->send((new Email)
            ->from('noreply@example.org')
            ->to('spieler@example.org')
            ->subject('Dein Anmeldelink – Volleyball')
            ->text('Hallo!'));

        $this->assertCount(1, $calls);
        $call = $calls[0];
        $this->assertSame('spieler@example.org', $call['to']);
        $this->assertStringContainsString('=?utf-8?', $call['subject']);
        $this->assertStringContainsString('Hallo!', $call['body']);
        $this->assertStringContainsString('From: noreply@example.org', $call['headers']);
        $this->assertStringContainsString('Content-Type: text/plain; charset=utf-8', $call['headers']);
        $this->assertStringNotContainsString('To:', $call['headers']);
        $this->assertStringNotContainsString('Subject:', $call['headers']);
        $this->assertSame('-fnoreply@example.org', $call['params']);
    }

    public function test_reports_a_refused_message(): void
    {
        $transport = new PhpMailTransport(fn (): bool => false);

        $this->expectException(TransportException::class);

        $transport->send((new Email)->from('noreply@example.org')->to('a@example.org')->subject('x')->text('y'));
    }
}
