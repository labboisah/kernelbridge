<?php

namespace App\Enums;

enum LeadStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Qualified = 'qualified';
    case DemoScheduled = 'demo_scheduled';
    case DemoCompleted = 'demo_completed';
    case Trial = 'trial';
    case QuotationSent = 'quotation_sent';
    case Negotiating = 'negotiating';
    case Won = 'won';
    case Lost = 'lost';
}
