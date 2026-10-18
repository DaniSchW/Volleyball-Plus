<?php

namespace App\Enums;

enum AuthTokenPurpose: string
{
    case Login = 'login';
    case Join = 'join';
}
