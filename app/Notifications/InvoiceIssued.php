<?php

namespace App\Notifications;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

final class InvoiceIssued extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Invoice $invoice)
    {
        $this->afterCommit();
    }

    public function via(object $n): array
    {
        return ['mail'];
    }

    public function toMail(object $n): MailMessage
    {
        $url = URL::temporarySignedRoute('invoices.customer.show', now()->addDays(60), ['invoice' => $this->invoice]);

        return (new MailMessage)->subject("Invoice {$this->invoice->invoice_number}")->greeting("Hello {$this->invoice->customer->name},")->line('Your KernelBridge invoice is ready.')->line("Amount due: {$this->invoice->currency} {$this->invoice->balance}")->action('View invoice', $url);
    }
}
