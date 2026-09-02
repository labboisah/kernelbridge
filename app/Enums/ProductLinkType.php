<?php

namespace App\Enums;

enum ProductLinkType: string
{
    case Website = 'website';
    case Demo = 'demo';
    case Documentation = 'documentation';
    case Support = 'support';
    case Other = 'other';
}
