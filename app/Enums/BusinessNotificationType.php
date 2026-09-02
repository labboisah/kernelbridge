<?php

namespace App\Enums;

enum BusinessNotificationType: string
{
    case DemoRequestReceived = 'demo_request_received';
    case DemoScheduled = 'demo_scheduled';
    case QuotationIssued = 'quotation_issued';
    case QuotationAccepted = 'quotation_accepted';
    case InvoiceIssued = 'invoice_issued';
    case PaymentVerified = 'payment_verified';
    case SubscriptionActivated = 'subscription_activated';
    case SubscriptionExpiring = 'subscription_expiring';
    case SubscriptionExpired = 'subscription_expired';
    case LicenseActivated = 'license_activated';
    case LicenseExpiring = 'license_expiring';
    case SupportTicketCreated = 'support_ticket_created';
    case SupportReplyReceived = 'support_reply_received';
    case StaleLeadFollowUp = 'stale_lead_follow_up';
}
