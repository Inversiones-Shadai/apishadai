<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class DesktopLicenseKeyCodeMail extends Mailable
{
    public function __construct(
        public readonly string $licenseLabel,
        public readonly string $product,
        public readonly string $keySuffix,
        public readonly int $actorId,
        public readonly string $code,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Autorizar consulta de clave de licencia de escritorio');
    }

    public function content(): Content
    {
        return new Content(text: 'emails.desktop-license-key-code');
    }
}
