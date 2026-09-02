<?php

namespace App\Enums;

enum CustomerStatus: string
{
    case Lead = 'lead';
    case Prospect = 'prospect';
    case Active = 'active';
    case Suspended = 'suspended';
    case Inactive = 'inactive';
}
