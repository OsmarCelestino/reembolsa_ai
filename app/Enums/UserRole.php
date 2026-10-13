<?php

namespace App\Enums;

/** Funcionário envia e vê os próprios pedidos; analista vê todos e revisa os dos outros. */
enum UserRole: string
{
    case Employee = 'employee';
    case Reviewer = 'reviewer';
}
