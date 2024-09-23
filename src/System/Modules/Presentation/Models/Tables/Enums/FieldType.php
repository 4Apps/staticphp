<?php

namespace System\Modules\Presentation\Models\Tables\Enums;

/**
 * This represents output type of a field in a form.
 * It can be filter or editable field in the table.
 */
enum FieldType: string
{
        // Input
    case TEXT = 'text';
    case INT = 'int';
    case DECIMAL = 'decimal';

    case DATE = 'date';
    case DATETIME = 'datetime';
    case DATEINTERVAL = 'dateinterval';

        // Textarea
    case MULTILINE_TEXT = 'multiline-text';

        // Switch / Checkbox
    case SWITCH = 'switch';
    case CHECKBOX = 'checkbox';

        // Select - Dropdown
    case SELECT = 'select';
    case SELECT_NO_YES = 'select_no_yes';
    case SELECT_MULTIPLE = 'select-multiple';

        // Specific cases
    case ROW_NUMBER = 'row-number';
    case SELECT_ALL_CHECKBOX = 'select-all-checkboxes';
}
