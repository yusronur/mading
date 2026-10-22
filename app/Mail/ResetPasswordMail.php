<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ResetPasswordMail extends Mailable
{
    public function __construct(
        public string $name,
        public string $username,
        public string $url,
        public string $appName,
        public string $schoolName,
        public int $minutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Atur ulang sandi {$this->appName}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.reset-password');
    }
}
