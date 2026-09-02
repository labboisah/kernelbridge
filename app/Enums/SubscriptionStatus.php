<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Pending = 'pending';
    case Trial = 'trial';
    case Active = 'active';
    case Grace = 'grace';
    case Expired = 'expired';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';
}
