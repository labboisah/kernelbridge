<?php

namespace App\Domain\Notifications\Services;

use App\Enums\BusinessNotificationType;
use App\Models\DemoRequest;
use App\Models\Invoice;
use App\Models\LicenseActivation;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\Subscription;
use App\Notifications\BusinessNotification;
use Illuminate\Support\Facades\Notification;

final readonly class BusinessLifecycleNotifications
{
    public function __construct(private BusinessNotificationDispatcher $dispatch) {}

    public function register(): void
    {
        DemoRequest::updated(function (DemoRequest $demo): void {
            if ($demo->wasChanged('status') && $demo->status->value === 'scheduled' && $demo->email) {
                Notification::route('mail', $demo->email)->notify(new BusinessNotification(BusinessNotificationType::DemoScheduled, 'Your KernelBridge demo is scheduled', 'Your demo session has been scheduled.', ['demo_request_uuid' => $demo->uuid, 'scheduled_at' => $demo->scheduled_at?->toISOString()]));
            }
        });

        Quotation::updated(function (Quotation $quotation): void {
            if (! $quotation->wasChanged('status')) {
                return;
            }
            if ($quotation->status->value === 'sent') {
                $this->databaseCopy($quotation->customer, BusinessNotificationType::QuotationIssued, 'Quotation issued', "Quotation {$quotation->quotation_number} is ready.", ['quotation_uuid' => $quotation->uuid]);
            } elseif ($quotation->status->value === 'accepted') {
                $this->dispatch->toCustomer($quotation->customer, new BusinessNotification(BusinessNotificationType::QuotationAccepted, 'Quotation accepted', "Quotation {$quotation->quotation_number} has been accepted.", ['quotation_uuid' => $quotation->uuid]));
            }
        });

        Invoice::updated(function (Invoice $invoice): void {
            if ($invoice->wasChanged('issued_at') && $invoice->issued_at) {
                $this->databaseCopy($invoice->customer, BusinessNotificationType::InvoiceIssued, 'Invoice issued', "Invoice {$invoice->invoice_number} is ready.", ['invoice_uuid' => $invoice->uuid]);
            }
        });

        Payment::updated(function (Payment $payment): void {
            if ($payment->wasChanged('status') && $payment->status->value === 'verified') {
                $this->dispatch->toCustomer($payment->customer, new BusinessNotification(BusinessNotificationType::PaymentVerified, 'Payment verified', "Payment {$payment->payment_number} has been verified.", ['payment_uuid' => $payment->uuid]));
            }
        });

        Subscription::updated(function (Subscription $subscription): void {
            if ($subscription->wasChanged('status') && $subscription->status->value === 'active') {
                $subscription->loadMissing(['customer', 'product']);
                $this->dispatch->toCustomer($subscription->customer, new BusinessNotification(BusinessNotificationType::SubscriptionActivated, 'Subscription activated', "Your {$subscription->product->name} subscription is active.", ['subscription_uuid' => $subscription->uuid]));
            }
        });

        LicenseActivation::created(function (LicenseActivation $activation): void {
            $activation->loadMissing('license.subscription.customer', 'license.subscription.product');
            $subscription = $activation->license->subscription;
            $this->dispatch->toCustomer($subscription->customer, new BusinessNotification(BusinessNotificationType::LicenseActivated, 'License activated', "Your {$subscription->product->name} license was activated on a device.", ['license_uuid' => $activation->license->uuid, 'activation_uuid' => $activation->uuid]));
        });
    }

    private function databaseCopy($customer, BusinessNotificationType $type, string $subject, string $message, array $context): void
    {
        $users = $customer->users()->get();
        if ($users->isNotEmpty()) {
            Notification::send($users, new BusinessNotification($type, $subject, $message, $context, channels: ['database']));
        }
    }
}
