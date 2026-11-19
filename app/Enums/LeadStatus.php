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

    public function next(): ?self
    {
        return match ($this) {
            self::New => self::Contacted,
            self::Contacted => self::Qualified,
            self::Qualified => self::DemoScheduled,
            self::DemoScheduled => self::DemoCompleted,
            self::DemoCompleted => self::Trial,
            self::Trial => self::QuotationSent,
            self::QuotationSent => self::Negotiating,
            self::Negotiating => self::Won,
            self::Won, self::Lost => null,
        };
    }
}
