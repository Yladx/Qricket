<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class InvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public $subscription;
    public $invoiceUrl;
    public $plan;

    /**
     * Create a new message instance.
     */
    public function __construct($subscription, $invoiceUrl, $plan)
    {
        $this->subscription = $subscription;
        $this->invoiceUrl = $invoiceUrl;
        $this->plan = $plan;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        return $this->subject('Your Invoice from ' . config('app.name'))
                    ->view('emails.invoice')
                    ->with([
                        'subscription' => $this->subscription,
                        'invoiceUrl' => $this->invoiceUrl,
                        'plan' => $this->plan,
                    ]);
    }
} 