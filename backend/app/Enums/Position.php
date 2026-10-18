<?php

namespace App\Enums;

/** Zuspiel, Diagonal, Mittelblock, Außenangriff, Libero, Universal. */
enum Position: string
{
    case Setter = 'setter';
    case Opposite = 'opposite';
    case Middle = 'middle';
    case Outside = 'outside';
    case Libero = 'libero';
    case Universal = 'universal';
}
