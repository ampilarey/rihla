<?php

namespace App\Mail;

use App\Models\Notice;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A notice, as the customer reads it in their inbox: the same headline and
 * words staff would send by WhatsApp, signed by the business it is from.
 */
class NoticeMail extends Mailable
{
    public function __construct(public readonly Notice $notice, public readonly string $business) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->notice->headline);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.notice');
    }
}
