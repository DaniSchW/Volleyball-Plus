<?php

namespace App\Enums;

/** How an account acts for a person: as that person (own) or as a parent (guardian). */
enum PersonRelation: string
{
    case Own = 'self';
    case Guardian = 'guardian';
}
