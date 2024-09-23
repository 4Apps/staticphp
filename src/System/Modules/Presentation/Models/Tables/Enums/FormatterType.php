<?php

namespace System\Modules\Presentation\Models\Tables\Enums;

/**
 * This represents data formatter type.
 */
enum FormatterType: string
{
    case TEXT = 'text';
    case INT = 'int';
    case DECIMAL = 'decimal';
    case DECIMAL1 = 'decimal1';
    case DECIMAL2 = 'decimal2';
    case DECIMAL3 = 'decimal3';
    case DECIMAL4 = 'decimal4';
    case BOOLEAN = 'boolean';
    case DATE = 'date';
    case DATETIME = 'datetime';
}
