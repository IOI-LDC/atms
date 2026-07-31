<?php

namespace App\Enums;

enum FormFieldType: string
{
    case BOOLEAN = 'boolean';
    case NUMERIC = 'numeric';
    case TEXT = 'text';
}
