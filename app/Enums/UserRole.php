<?php

namespace App\Enums;

enum UserRole: string
{
    case Employee = 'employee';
    case Reviewer = 'reviewer';
}
