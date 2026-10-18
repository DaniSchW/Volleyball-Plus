<?php

namespace App\Mail;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Plain-text notification (event changed, reminder, release at 18, …).
 * Texts arrive already translated for the recipient.
 */
class NotificationMail extends Mailable
{
    use Queueable;

    public function __construct(
        public Tenant $tenant,
        public string $title,
        public string $body,
        public ?string $url = null,
        public bool $settingsHint = true,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address((string) config('mail.from.address'), $this->tenant->mail_from_name ?: $this->tenant->name),
            subject: $this->title,
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.notification',
            with: [
                'footer' => $this->settingsHint
                    ? __('notifications.mail.footer', ['url' => rtrim((string) config('app.url'), '/').'/'.$this->tenant->slug.'/profile'])
                    : null,
            ],
        );
    }
}
