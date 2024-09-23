<?php

namespace System\Modules\Presentation\Models\Tables\Enums;

/**
 * This represents the type of a column as it is stored in data source.
 * It is also used to determine how to search in the table.
 */
enum ColumnType: string
{
    case TEXT = 'text';

        // By default we search for text via LIKE, this is for exact match
    case TEXT_EXACT = 'text_exact';

    case INT = 'int';
    case DECIMAL = 'decimal';

    case BOOLEAN = 'boolean';

    case DATE = 'date';
    case DATETIME = 'datetime';

    case DATE_NATIVE = 'date_native';
    case DATETIME_NATIVE = 'datetime_native';

        // Specific cases
    case ROW_NUMBER = 'row-number';
    case SELECT_ALL_CHECKBOX = 'select-all-checkboxes';
}
