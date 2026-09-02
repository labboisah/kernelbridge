<?php

namespace App\Enums;

enum SupportCategory: string
{
    case Technical = 'technical';
    case Billing = 'billing';
    case License = 'license';
    case Deployment = 'deployment';
    case Training = 'training';
    case General = 'general';
}
