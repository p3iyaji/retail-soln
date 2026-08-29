<?php

namespace Modules\Notifications\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Modules\Notifications\Models\Notification;

class NotificationMessage extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Notification $notification) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->notification->subject ?? 'Notification',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'notifications::mail.notification',
            with: [
                'greetingName' => $this->notification->recipient_name,
                'heading' => $this->notification->subject ?? 'Notification',
                'bodyText' => $this->notification->body ?? '',
                'lines' => $this->summaryLines(),
            ],
        );
    }

    /**
     * @return array<string, scalar>
     */
    private function summaryLines(): array
    {
        $payload = $this->notification->payload ?? [];
        $lines = [];
        foreach ($payload as $key => $value) {
            if (is_scalar($value)) {
                $lines[(string) $key] = $value;
            }
        }

        return $lines;
    }
}
