<?php

namespace App\Enums;

enum PublicationStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Published = 'published';
    case Archived = 'archived';
}
