<?php

namespace App\Enums;

enum ProductMediaType: string
{
    case Screenshot = 'screenshot';
    case Video = 'video';
    case Document = 'document';
}
