<?php

namespace App\Enums;

enum Severity: string
{
    /** Recusa o pedido. */
    case Hard = 'hard';

    /** Manda para revisão humana. */
    case Soft = 'soft';
}
