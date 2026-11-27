<?php

namespace App\Domain\Partners;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\PartnerClientMilestone;
use Carbon\CarbonInterface;
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

    public function installation(Subscription $subscription, ?string $activationCode = null, ?CarbonInterface $expiresAt = null): void
    {
        $subscription->loadMissing(['customer', 'product']);
        if (! $subscription->customer->partner_user_id) {
            return;
        }
        $subject = 'Installation and activation: '.$subscription->product->name;
        $message = "The {$subscription->product->name} subscription for {$subscription->customer->name} is paid and ready for setup. The client can sign in to view available installation and activation options, or contact support for assistance.";
        $partner = User::find($subscription->customer->partner_user_id);
        if ($partner) {
            $partner->notify(new PartnerClientMilestone($subject, $message, 'View setup details', route('partner.activation-codes')));
        }

        if ($subscription->customer->email) {
            if ($activationCode !== null && $expiresAt !== null) {
                $message = "Your {$subscription->product->name} subscription is paid. Your one-time installation activation code is {$activationCode}. It expires on ".$expiresAt->format('M j, Y H:i T').'. Keep this code private. Sign in to your KernelBridge client portal to view installation and setup details.';
            }
            Notification::route('mail', $subscription->customer->email)
                ->notify(new PartnerClientMilestone($subject, $message, 'View setup details', route('portal.licenses.index')));
        } else {
            Log::warning('Partner client notification not sent: customer has no email.', ['customer_id' => $subscription->customer->id, 'subject' => $subject]);
        }
    }

    public function activationCode(Subscription $subscription, string $activationCode, CarbonInterface $expiresAt, bool $replaced): void
    {
        $subscription->loadMissing(['customer', 'product']);
        if (! $subscription->customer->email) {
            Log::warning('Activation code email not sent: customer has no email.', ['customer_id' => $subscription->customer->id]);

            return;
        }

        $message = "Your new {$subscription->product->name} installation activation code is {$activationCode}. It expires on ".$expiresAt->format('M j, Y H:i T').'. Keep this code private.';
        if ($replaced) {
            $message .= ' Any unused previous code has been invalidated.';
        }

        Notification::route('mail', $subscription->customer->email)->notify(new PartnerClientMilestone(
            'New installation activation code: '.$subscription->product->name,
            $message,
            'View setup details',
            route('portal.licenses.index'),
        ));
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
