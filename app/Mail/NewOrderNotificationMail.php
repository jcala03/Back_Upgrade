<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewOrderNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Order $order,
        public array $business,
    ) {
        $this->order->loadMissing('items');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Nueva orden {$this->order->order_number} - {$this->business['business_name']}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.orders.business-notification',
            with: [
                'order' => $this->order,
                'business' => $this->business,
            ],
        );
    }
}
