<?php

namespace App\Mail;

use App\Enums\AuthTokenPurpose;
use App\Models\Tenant;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class MagicLinkMail extends Mailable
{
    public function __construct(
        public ?Tenant $tenant,
        public string $url,
        public AuthTokenPurpose $purpose,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address((string) config('mail.from.address'), $this->tenant?->mail_from_name ?: $this->name()),
            subject: __("auth.mail.{$this->purpose->value}.subject", ['tenant' => $this->name()]),
        );
    }

    /** Club name, or the app's name for a login from the start page. */
    private function name(): string
    {
        return $this->tenant?->name ?? (string) config('app.name');
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.magic-link',
            with: [
                'intro' => __("auth.mail.{$this->purpose->value}.intro", ['tenant' => $this->name()]),
                'validity' => __('auth.mail.validity', ['minutes' => config('security.magic_link_minutes')]),
                'ignore' => __('auth.mail.ignore'),
            ],
        );
    }
}
