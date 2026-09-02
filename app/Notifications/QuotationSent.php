<?php

namespace App\Notifications;

use App\Models\Quotation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

final class QuotationSent extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Quotation $quotation)
    {
        $this->afterCommit();
    }

    public function via(object $n): array
    {
        return ['mail'];
    }

    public function toMail(object $n): MailMessage
    {
        $url = URL::temporarySignedRoute('quotations.customer.show', now()->addDays(30), ['quotation' => $this->quotation]);

        return (new MailMessage)->subject("Quotation {$this->quotation->quotation_number}")->greeting("Hello {$this->quotation->customer->name},")->line('KernelBridge Technologies has prepared a quotation for you.')->line("Total: {$this->quotation->currency} {$this->quotation->total}")->action('View quotation', $url)->line('This secure link expires in 30 days.');
    }
}
