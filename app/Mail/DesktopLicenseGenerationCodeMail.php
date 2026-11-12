<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class DesktopLicenseGenerationCodeMail extends Mailable
{
    public function __construct(
        public readonly string $customerName,
        public readonly string $licenseLabel,
        public readonly string $product,
        public readonly int $seats,
        public readonly int $termMonths,
        public readonly int $actorId,
        public readonly string $code,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Autorizar creación de licencia de escritorio');
    }

    public function content(): Content
    {
        return new Content(text: 'emails.desktop-license-generation-code');
    }
}
