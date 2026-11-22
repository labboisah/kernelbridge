<?php

namespace App\Domain\Partners;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\PartnerClientMilestone;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

final class PartnerClientNotifications
{
    public function invoice(Invoice $invoice): void
    {
        $invoice->loadMissing('customer');
        if (! $invoice->customer->partner_user_id) {
            return;
        }
        $partner = User::find($invoice->customer->partner_user_id);
        if ($partner) {
            $partner->notify(new PartnerClientMilestone(
                'Invoice ready: '.$invoice->invoice_number,
                "The invoice for {$invoice->customer->name} is ready. Amount due: {$invoice->currency} {$invoice->balance}.",
                'View invoice and continue payment', route('partner.invoices.show', $invoice->uuid),
            ));
        }
    }

    public function payment(Customer $customer, string $reference, string $amount, string $currency): void
    {
        if (! $customer->partner_user_id) {
            return;
        }
        $subject = 'Payment confirmed: '.$reference;
        $message = "A payment of {$currency} {$amount} for {$customer->name} has been verified.";
        $this->sendBoth($customer, $subject, $message, 'View payments', route('partner.payments'), route('portal.payments.index'));
    }

    public function installation(Subscription $subscription): void
    {
        $subscription->loadMissing(['customer', 'product']);
        if (! $subscription->customer->partner_user_id) {
            return;
        }
        $this->sendBoth(
            $subscription->customer,
            'Installation and activation: '.$subscription->product->name,
            "The {$subscription->product->name} subscription for {$subscription->customer->name} is paid and ready for setup. The client can sign in to view available installation and activation options, or contact support for assistance.",
            'View setup details',
            route('partner.activation-codes'),
            route('portal.licenses.index'),
        );
    }

    private function sendBoth(Customer $customer, string $subject, string $message, string $label, string $partnerUrl, string $clientUrl): void
    {
        $partner = User::find($customer->partner_user_id);
        if ($partner) {
            $partner->notify(new PartnerClientMilestone($subject, $message, $label, $partnerUrl));
        }
        if ($customer->email) {
            Notification::route('mail', $customer->email)->notify(new PartnerClientMilestone($subject, $message, $label, $clientUrl));
        } else {
            Log::warning('Partner client notification not sent: customer has no email.', ['customer_id' => $customer->id, 'subject' => $subject]);
        }
    }
}
