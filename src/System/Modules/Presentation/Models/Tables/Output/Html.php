<?php

namespace System\Modules\Presentation\Models\Tables\Output;

use Exception;
use System\Modules\Utils\Models\ExtendedDateTime;
use System\Modules\Presentation\Models\Tables\Interfaces\OutputInterface;
use System\Modules\Presentation\Models\Tables\Enums\TableType;
use System\Modules\Presentation\Models\Tables\Enums\ColumnType;
use System\Modules\Presentation\Models\Tables\Enums\EditableTableType;
use System\Modules\Presentation\Models\Tables\Enums\FieldType;
use System\Modules\Presentation\Models\Tables\Enums\FormatterType;
use System\Modules\Presentation\Models\Tables\Enums\RowPosition;
use System\Modules\Presentation\Models\Tables\Enums\SortDirection;
use System\Modules\Presentation\Models\Tables\Traits\TableInstance;
use System\Modules\Presentation\Models\Tables\Column;
use System\Modules\Presentation\Models\Tables\Utils;

class Html implements OutputInterface
{
    use TableInstance;

    public TableType $type = TableType::FULL_HTML;
    public string $classNames = 'table';
    public array $tableAttributes = [];

    /**
     * Editable type for the table.
     */
    public EditableTableType $editableTableType = EditableTableType::WHOLE_TABLE;

    /**
     * Elements can be string or Closure. If its a Closure, row index and row data are passed as arguments.
     */
    public array $dataRowAttributes = [];
    public array $dataRowClasses = ['data-row'];
    public array $dataColumnAttributes = [];
    public array $dataColumnClasses = ['data-col'];


    /**
     * Returns html input attribute - "value" with its value
     *
     * @access public
     * @param  string       $field
     * @param  string|null  $compare (default: null)
     * @return string
     */
    public function inputValue(string $value, ?string $compare = null, bool $checkbox = false): string
    {
        if ($compare !== null) {
            if ($value === $compare) {
                return $checkbox === true ? ' checked="checked"' : ' selected="selected"';
            }

            return '';
        }

        $value = str_replace('"', '&quot;', $value);
        return " value=\"{$value}\"";
    }

    public function localeNumberFormat($number, $decimals = 2)
    {
        $locale = localeconv();
        return number_format($number ?? 0, $decimals, $locale['decimal_point'], $locale['thousands_sep']);
    }

    public function formatData($data, $formatter): string
    {
        if ($formatter === null) {
            return $data;
        }

        if (is_callable($formatter)) {
            return $formatter($data);
        }

        switch ($formatter) {
            case FormatterType::TEXT:
                return "{$data}";

            case FormatterType::INT:
                return $this->localeNumberFormat($data, 0);

            case FormatterType::DECIMAL:
                return $this->localeNumberFormat((float)$data) + 0;

            case FormatterType::DECIMAL1:
                return $this->localeNumberFormat((float)$data, 1);

            case FormatterType::DECIMAL2:
                return $this->localeNumberFormat((float)$data, 2);

            case FormatterType::DECIMAL3:
                return $this->localeNumberFormat((float)$data, 3);

            case FormatterType::DECIMAL4:
                return $this->localeNumberFormat((float)$data, 4);

            case FormatterType::BOOLEAN:
                return $data == 1 ? 'Yes' : 'No';

            case FormatterType::DATE:
                if ($data instanceof ExtendedDateTime) {
                    return $data->formatDate();
                }
                if ($data instanceof \DateTime) {
                    return $data->format('Y-m-d');
                }
                return $data;

            case FormatterType::DATETIME:
                if ($data instanceof ExtendedDateTime) {
                    return $data->formatDateTime();
                }
                if ($data instanceof \DateTime) {
                    return $data->format('Y-m-d H:i:s');
                }
                return $data;

            default:
                return $data;
        }

        return $data;
    }


    // ! Sort

    /**
     * Get html link or url for specified table and column
     *
     * @access public
     * @param  Column $forColumn
     * @param  bool $urlOnly (default: false)
     * @return string Returns link for a column
     */
    public function sortUrl(Column $forColumn): string
    {
        $newDirection = ($forColumn->id === $this->tableInstance->sort->currentColumn()->id
            && $this->tableInstance->sort->currentDirection() === SortDirection::ASC
            ? 'desc' : 'asc'
        );
        $sortData = "{$forColumn->id}={$newDirection}";
        $url = $this->tableInstance->sort->url();
        $url = str_replace('%sort', $sortData, $url);

        return $url;
    }

    /**
     * Get html link for a column
     *
     * @access public
     * @param  Column $forColumn
     * @return string Returns string containing html link
     */
    public function sortLinkHtml(Column $forColumn): string
    {
        if ($forColumn->sortEnabled === false) {
            return $forColumn->title;
        }

        $url = $this->sortUrl($forColumn);

        $html = '';
        if ($forColumn->id === $this->tableInstance->sort->currentColumn()->id) {
            $html = '&nbsp;&nbsp;<span class="fa fas fa-sort-alpha-';
            $html .= ($this->tableInstance->sort->currentDirection() === SortDirection::ASC ? 'down' : 'up');
            $html .= ' sort-icon"></span>';
        }

        $link_addon = (empty($forColumn->sortLinkAttribute) ? '' : $forColumn->sortLinkAttribute);
        if (!empty($forColumn->description)) {
            $link_addon .=
                ' title="' . $forColumn->description
                . '" class="tooltip-line" data-toggle="tooltip" data-placement="top"';
        }
        $link = '<div class="hidden-print d-print-none">'
            . '<a href="' . $url . '" ' . $link_addon . '>' . $forColumn->title . '</a></div>';
        $link .= '<div class="visible-print d-none d-print-inline">' . $forColumn->title . '</div>' . $html;

        $link = '<div class="d-flex align-items-center">' . $link . '</div>';
        return $link;
    }

    /**
     * Returns table's header row for all columns
     *
     * @access public
     * @return string   Returns string containing table row
     */
    public function titleRow(): string
    {
        if ($this->tableInstance->sort === null) {
            throw new Exception("Sort is not initialized");
        }

        $html = '<tr>';

        /** @var Column $column */
        $column = null;
        foreach ($this->tableInstance->columns as $column) {
            $showColumn = true;
            if (is_callable($column->showColumn)) {
                $showColumn = $column->showColumn;
                $showColumn = $showColumn();
            } else {
                $showColumn = $column->showColumn;
            }

            $linkHtml = $this->sortLinkHtml($column);
            if ($showColumn !== false) {
                // Attributes
                $tmp = $column->columnAttributes;
                $tmp = Utils::runClosures($tmp, [$column]);
                $attributes = implode(' ', $tmp);
                $attributes = " {$attributes} ";

                // Classes
                $tmp = $column->columnClasses;
                $tmp = Utils::runClosures($tmp, [$column]);
                $tmp = implode(' ', $tmp);
                $attributes .= " class=\"{$tmp}\" ";

                $html .= '<th' . $attributes . '>' . $linkHtml . '</th>';
            }
        }
        $html .= '</tr>';

        return $html;
    }


    // ! Filters
    /**
     * Returns html input attribute - extracted from specific filter
     *
     * @access public
     * @param  string       $field
     * @param  string|null  $compare (default: null)
     * @return string
     */
    public function filterInputValue(string $field, ?string $compare = null, bool $checkbox = false): string
    {
        $parsedData = $this->tableInstance->filter->parsedData();

        if ($compare !== null) {
            $attributeName = "selected";
            if ($checkbox === true) {
                $attributeName = "checked";
            }
            if (isset($parsedData[$field]['value'])) {
                if (is_array($parsedData[$field]['value'])) {
                    if (in_array($compare, $parsedData[$field]['value'])) {
                        return " {$attributeName}=\"{$attributeName}\"";
                    }
                } elseif ($parsedData[$field]['value'] === $compare) {
                    return " {$attributeName}=\"{$attributeName}\"";
                }
            }

            return '';
        }

        $attributes = '';
        if ($checkbox === true) {
            if (!empty($parsedData[$field])) {
                $attributes .= ' checked="checked"';
            }
        } elseif (isset($parsedData[$field])) {
            $attributes .= ' value="' . str_replace('"', '&quot;', $parsedData[$field]['title']) . '"';
            if ($parsedData[$field]['title'] != $parsedData[$field]['value']) {
                $attributes .= ' data-value="' . str_replace('"', '&quot;', $parsedData[$field]['value']) . '"';
            }
        }

        return $attributes;
    }

    /**
     * Returns filter input field by $type with value filled in, or selected in case of select html element.
     *
     * @access public
     * @param  string   $name
     * @param  mixed    $value (default: [empty string])
     * @return string|bool
     */
    public function filterInputField(Column $forColumn, string $value = ''): string
    {
        if ($forColumn->filterHidden === true) {
            return '';
        }

        // Attributes
        $attributes = ' id="filter_' . $forColumn->id . '" ';

        $tmp = $forColumn->filterInputAttributes;
        $tmp = Utils::runClosures($tmp, [$forColumn, $value]);
        $tmp = implode(' ', $tmp);
        $attributes .= " {$tmp} ";

        if ($forColumn->filterEnabled === false) {
            $attributes .= ' disabled="disabled"';
        }

        // Classes
        $tmp = $forColumn->filterInputClasses;
        $tmp = Utils::runClosures($tmp, [$forColumn, $value]);
        $tmp = implode(' ', $tmp);
        $classes = "form-control form-control-sm input-xs filter {$tmp} ";

        $html = '';
        switch ($forColumn->filterFieldType) {
            case FieldType::MULTILINE_TEXT:
                throw new \Exception("Multiline text is not supported for filter");

            case FieldType::ROW_NUMBER:
                throw new \Exception("Row number is not supported for filter");

            case FieldType::DATE:
            case FieldType::DATETIME:
            case FieldType::DATEINTERVAL:
                if ($forColumn->filterFieldType == FieldType::DATE) {
                    $classes .= ' datepicker-trigger';
                } elseif ($forColumn->filterFieldType == FieldType::DATETIME) {
                    $classes .= ' datetimepicker-trigger';
                } elseif ($forColumn->filterFieldType == FieldType::DATEINTERVAL) {
                    $classes .= ' dateintervalpicker-trigger';
                }

                if (!empty($forColumn->filterDateValue)) {
                    $attributes .= ' data-unix="' . $forColumn->filterDateValue . '"';
                }

                // no break

            case FieldType::TEXT:
            case FieldType::INT:
            case FieldType::DECIMAL:
                $fieldType = 'text';
                if (
                    $forColumn->filterFieldType == FieldType::INT
                    || $forColumn->filterFieldType == FieldType::DECIMAL
                ) {
                    $fieldType = 'number';
                }
                $html = '<input type="' . $fieldType . '" class="' . $classes . '"' . $attributes;
                if (!empty($forColumn->filterTitle)) {
                    $html .= ' placeholder="' . $forColumn->filterTitle . '" ';
                }
                $html .= ' ' . $this->filterInputValue($forColumn->id) . '>';
                break;

            case FieldType::SWITCH:
            case FieldType::CHECKBOX:
            case FieldType::SELECT:
            case FieldType::SELECT_NO_YES:
            case FieldType::SELECT_MULTIPLE:
                $selectOptions = $forColumn->filterSelectOptions;
                $selectOptionsGroups = $forColumn->filterSelectOptionsGroups;
                if (
                    $forColumn->filterFieldType == FieldType::SWITCH
                    || $forColumn->filterFieldType == FieldType::CHECKBOX
                    || $forColumn->filterFieldType == FieldType::SELECT_NO_YES
                ) {
                    $selectOptions = [0 => 'No', 1 => 'Yes'];
                }

                if (
                    isset($selectOptions) == false
                    || is_array($selectOptions) === false
                ) {
                    throw new \Exception("Value for {$forColumn->id} should be [key => value] array");
                }
                if ($forColumn->filterFieldType == FieldType::SELECT_MULTIPLE) {
                    $attributes .= ' multiple="multiple" size="3" ';
                }

                $parsedData = $this->tableInstance->filter->parsedData();
                $classes .= ' form-select form-select-sm';
                $html = '<select class="' . $classes . '"' . $attributes . '>';
                if (count($selectOptions) == 0 && isset($parsedData[$forColumn->id])) {
                    $html .= '<option value="' . $parsedData[$forColumn->id]['value'] . '">'
                        . $parsedData[$forColumn->id]['title'] . '</option>';
                } elseif (empty($forColumn->filterSelectSkipEmptyDefault)) {
                    $html .= '<option value=""'
                        . (!empty($forColumn->filterSelectDefaultDisabled) ? ' disabled="disabled"' : '') . '>'
                        . ($forColumn->filterTitle ?? '')
                        . '</option>';
                }
                if (!empty($selectOptionsGroups) && is_array($selectOptionsGroups)) {
                    foreach ($selectOptionsGroups as $gkey => $gitem) {
                        $final_optgroup_title = (empty($forColumn->filterSelectOptionsGroupTitleKey)
                            ? $gitem
                            : $gitem[$forColumn->filterSelectOptionsGroupTitleKey]
                        );
                        $html .= '<optgroup label="' . $final_optgroup_title . '">';

                        if (isset($selectOptions[$gkey])) {
                            foreach ($selectOptions[$gkey] as $key => $item) {
                                $finalId = (empty($forColumn->filterSelectOptionsIdKey)
                                    ? $key
                                    : $item[$forColumn->filterSelectOptionsIdKey]
                                );
                                $finalTitle = (empty($forColumn->filterSelectOptionsTitleKey)
                                    ? $item
                                    : $item[$forColumn->filterSelectOptionsTitleKey]
                                );
                                $html .= '<option value="' . $finalId . '"'
                                    . $this->filterInputValue($forColumn->id, $finalId) . '>'
                                    . $finalTitle
                                    . '</option>';
                            }
                        }

                        $html .= '</optgroup>';
                    }
                } else {
                    foreach ($selectOptions as $key => $item) {
                        $finalId = (empty($forColumn->filterSelectOptionsIdKey)
                            ? $key
                            : $item[$forColumn->filterSelectOptionsIdKey]
                        );
                        $finalTitle = (empty($forColumn->filterSelectOptionsTitleKey)
                            ? $item
                            : $item[$forColumn->filterSelectOptionsTitleKey]
                        );
                        $html .= '<option value="' . $finalId . '"'
                            . $this->filterInputValue($forColumn->id, $finalId)
                            . '>'
                            . $finalTitle
                            . '</option>';
                    }
                }
                $html .= '</select>';
                break;

            case FieldType::SELECT_ALL_CHECKBOX:
                $id = 'parent_checkbox_' . $this->tableInstance->tableId();
                $html = '<div class="form-check">';
                $html .= '<input type="checkbox" class="form-check-input parent_checkbox" id="' . $id . '">';
                $html .= '<label for="' . $id . '"></label>';
                $html .= '</div>';
                break;
        }

        return $html;
    }

    /**
     * Returns table's filter row for all columns
     *
     * @access public
     * @return string   Returns string containing table row
     */
    public function filtersRow(): string
    {
        if ($this->tableInstance->filter === null) {
            throw new Exception("Filter is not initialized");
        }

        $html = '<tr id="table_filters_' . $this->tableInstance->tableId() . '">' . "\n";

        /** @var Column $column */
        $column = null;
        foreach ($this->tableInstance->columns as $column) {
            $showColumn = true;
            if (is_callable($column->showColumn)) {
                $showColumn = $column->showColumn;
                $showColumn = $showColumn();
            } else {
                $showColumn = $column->showColumn;
            }

            $fieldHtml = $this->filterInputField($column);
            if ($showColumn !== false) {
                // Attributes
                $tmp = $column->columnAttributes;
                $tmp = Utils::runClosures($tmp, [$column]);
                $attributes = implode(' ', $tmp);
                $attributes = " {$attributes} ";

                // Classes
                $tmp = $column->columnClasses;
                $tmp = Utils::runClosures($tmp, [$column]);
                $tmp = implode(' ', $tmp);
                $attributes .= " class=\"{$tmp}\" ";

                $html .= "<td{$attributes}>{$fieldHtml}</td>\n";
            }
        }

        $html .= "</tr>\n";
        return $html;
    }


    // ! BODY
    public function htmlDataRow(int $rowIndex, array $rowItem, string $title = '', array $rowClasses = []): string
    {
        $columnCount = count($this->tableInstance->columns);
        $html = '';
        if (is_callable($rowItem)) {
            $html .= $rowItem($rowIndex, $rowItem, $columnCount, $title);
        } else {
            // Attributes
            $attributes = '';
            if (!empty($this->dataRowAttributes)) {
                $tmp = $this->dataRowAttributes;
                $tmp = Utils::runClosures($tmp, [$rowIndex, $rowItem, $columnCount]);
                $tmp = array_filter($tmp);
                $tmp = implode(' ', $tmp);
                $attributes .= " {$tmp} ";
            }

            // Classes
            $rowClasses = array_merge($this->dataRowClasses, $rowClasses);
            if (!empty($rowClasses)) {
                $tmp = $rowClasses;
                $tmp = Utils::runClosures($tmp, [$rowIndex, $rowItem, $columnCount]);
                $tmp = array_filter($tmp);
                if (!empty($tmp)) {
                    $tmp = implode(' ', $tmp);
                    $attributes .= " class=\"{$tmp}\" ";
                }
            }

            $html = '<tr title="' . $title . '"' . $attributes . '>';
            foreach ($this->tableInstance->columns as $column) {
                // Show / Hide column
                $showColumn = true;
                if (is_callable($column->showColumn)) {
                    $showColumn = $column->showColumn;
                    $showColumn = $showColumn();
                } else {
                    $showColumn = $column->showColumn;
                }
                if ($showColumn === false) {
                    continue;
                }

                // Data value
                $dataValue = '';
                if (is_callable($column->dataKey)) {
                    $dataKey = $column->dataKey;
                    $dataValue = $dataKey($column, $rowIndex, $rowItem, $columnCount);
                } elseif (isset($rowItem[$column->dataKey])) {
                    $dataValue = $rowItem[$column->dataKey];
                }

                // Data formatter
                $dataValue = $this->formatData($dataValue, $column->dataFormatter);

                // Special rows
                if ($rowIndex < 0) {
                    $html .= "<td>{$dataValue}</td>\n";
                    continue;
                }

                $idValue = $rowIndex;
                if (is_callable($column->idKey)) {
                    $idKey = $column->idKey;
                    $idValue = $idKey($column, $rowIndex, $rowItem, $columnCount);
                } elseif (isset($rowItem[$column->idKey])) {
                    $idValue = $rowItem[$column->idKey];
                } elseif (is_callable($this->tableInstance->idKey)) {
                    $idKey = $this->tableInstance->idKey;
                    $idValue = $idKey($column, $rowIndex, $rowItem, $columnCount);
                } elseif (isset($rowItem[$this->tableInstance->idKey])) {
                    $idValue = $rowItem[$this->tableInstance->idKey];
                }

                // Is Editable
                $isEditable = (Utils::expandClosure($column->isEditable)
                    && Utils::expandClosure($this->tableInstance->isEditable)
                );
                $selectOptions = $column->editSelectOptions;

                // Override data value with edit key if its present
                if ($column->editKey === null) {
                    $column->editKey = $column->dataKey;
                }
                $editValue = $dataValue;
                if (
                    $isEditable === true
                    && !empty($column->editKey)
                ) {
                    if (is_callable($column->editKey)) {
                        $editKey = $column->editKey;
                        $editValue = $editKey($column, $rowIndex, $rowItem, $columnCount);
                    } elseif (isset($rowItem[$column->editKey])) {
                        $editValue = $rowItem[$column->editKey];
                    }
                }

                // Set data column classes and attributes
                $dataColumnClasses = $this->dataColumnClasses;
                if (!empty($column->dataColumnClasses)) {
                    $dataColumnClasses += $column->dataColumnClasses;
                }
                $dataColumnAttributes = $this->dataColumnAttributes;
                if (!empty($column->dataColumnAttributes)) {
                    $dataColumnAttributes += $column->dataColumnAttributes;
                }

                // Prefix and Addon
                $prefix = $column->dataColumnPrefix;
                $addon = $column->dataColumnAddon;

                switch ($column->type) {
                    case ColumnType::ROW_NUMBER:
                        $dataColumnClasses[] = 'text-center col-md-c-1';
                        $number = $rowIndex + 1;
                        $dataValue = "{$number}.";
                        break;

                    case ColumnType::SELECT_ALL_CHECKBOX:
                        $dataColumnClasses[] = 'text-center col-md-c-1';
                        $dataValue = <<<EOL
                            <div class="form-check">
                                <input
                                    type="checkbox"
                                    class="form-check-input child_checkbox"
                                    id="parent_checkbox_{$idValue}" data-id="{$idValue}">
                                <label class="form-check-label" for="parent_checkbox_{$idValue}"></label>
                            </div>
                        EOL;
                        break;
                }

                switch ($column->editFieldType) {
                    case FieldType::SWITCH:
                        // Checked
                        $checked = $dataValue == 1 ? ' checked="checked"' : '';

                        $classes = '';
                        $disabled = ' disabled="disabled" ';
                        if ($isEditable === true) {
                            $classes = ' update_field ';
                            $disabled = '';
                        }

                        $switchValue = Utils::expandClosure($column->switchValue);
                        $dataValue = <<<EOL
                            <div class="form-check form-switch">
                                <input
                                    type="checkbox"
                                    name="{$column->id}"
                                    id="{$column->id}_{$idValue}"
                                    value="{$switchValue}"
                                    class="form-check-input{$classes}"
                                    {$checked}
                                    {$disabled}
                                >
                                <label class="form-check-label" for="{$column->id}_{$idValue}"></label>
                            </div>
                        EOL;
                        break;

                    case FieldType::CHECKBOX:
                        // Checked
                        $checked = $dataValue == 1 ? ' checked="checked"' : '';

                        $classes = '';
                        $disabled = ' disabled="disabled" ';
                        if ($isEditable === true) {
                            $classes = ' update_field ';
                            $disabled = '';
                        }

                        $switchValue = Utils::expandClosure($column->switchValue);
                        $dataValue = <<<EOL
                            <div class="form-check">
                                <input
                                    type="checkbox"
                                    name="{$column->id}"
                                    id="{$column->id}_{$idValue}"
                                    value="{$switchValue}"
                                    class="form-check-input{$classes}"
                                    {$checked}
                                    {$disabled}
                                >
                                <label class="form-check-label" for="{$column->id}_{$idValue}"></label>
                            </div>
                        EOL;
                        break;

                    case FieldType::SELECT:
                    case FieldType::SELECT_NO_YES:
                    case FieldType::SELECT_MULTIPLE:
                        // We need to override few things if conditions are met
                        if ($column->editFieldType == FieldType::SELECT_NO_YES) {
                            $selectOptions = [0 => 'No', 1 => 'Yes'];
                        }

                        // Check for editability
                        if ($isEditable === false || $this->editableTableType === EditableTableType::BY_FIELD) {
                            break;
                        }

                        if (
                            isset($selectOptions) == false
                            || is_array($selectOptions) === false
                        ) {
                            throw new \Exception("Value for {$column->id} should be [key => value] array");
                        }
                        if ($column->editFieldType == FieldType::SELECT_MULTIPLE) {
                            $attributes .= ' multiple="multiple" size="3" ';
                        }

                        $classes = 'form-control input-xs update_field';
                        $selectField = "<select class=\"{$classes}\" name=\"{$column->id}\""
                            . " id=\"{$column->id}_{$idValue}\">";
                        foreach ($selectOptions as $key => $item) {
                            $finalId = (empty($column->filterSelectOptionsIdKey)
                                ? $key
                                : $item[$column->filterSelectOptionsIdKey]
                            );
                            $finalTitle = (empty($column->filterSelectOptionsTitleKey)
                                ? $item
                                : $item[$column->filterSelectOptionsTitleKey]
                            );
                            $selected = self::inputValue($editValue, $key);
                            $selectField .= "<option value=\"{$finalId}\" {$selected}>{$finalTitle}</option>";
                        }
                        $selectField .= '</select>';
                        $dataValue = $selectField;
                        break;

                    case FieldType::MULTILINE_TEXT:
                        if ($isEditable === false || $this->editableTableType === EditableTableType::BY_FIELD) {
                            break;
                        }

                        $dataValue = str_replace(['<', '>'], ['&lt;', '&gt;'], $editValue);
                        $dataValue = <<<EOL
                            <textarea
                                class="form-control input-xs update_field"
                                name="{$column->id}"
                                id="{$column->id}_{$idValue}"
                                rows="2"
                            >{$dataValue}</textarea>
                        EOL;
                        break;

                    default:
                        if ($isEditable === false || $this->editableTableType === EditableTableType::BY_FIELD) {
                            break;
                        }

                        $classes = '';
                        if (
                            in_array(
                                $column->type,
                                [FieldType::DATE, FieldType::DATEINTERVAL, FieldType::DATETIME]
                            )
                        ) {
                            $classes = ' datepicker-trigger';
                        }

                        $fieldType = 'text';
                        if (
                            $column->editFieldType == FieldType::INT
                            || $column->editFieldType == FieldType::DECIMAL
                        ) {
                            $fieldType = 'number';
                        }

                        $dataValue = self::inputValue($editValue);
                        $dataValue = <<<EOL
                            <input
                                type="{$fieldType}"
                                class="form-control input-xs update_field{$classes}"
                                name="{$column->id}"
                                id="{$column->id}_{$idValue}"
                                {$dataValue}>
                        EOL;
                        break;
                }

                // Escape HTML
                if ($isEditable === false && $column->escapeDataHtml === true) {
                    $dataValue = str_replace(['<', '>'], ['&lt;', '&gt;'], $dataValue);
                }

                // Expandable Text
                if ($isEditable === true && !empty($column->expandableText)) {
                    throw new \Exception("Expandable text is not supported for editable columns");
                }

                if (!empty($column->expandableText)) {
                    $dataColumnClasses[] = 'text-cell';

                    $dataValue = <<<EOL
                        <div class="truncated-text">{$dataValue}</div>
                        <div class="expand-switch">Expand</div>
                    EOL;
                }

                // Editable fields
                if ($isEditable === true && $this->editableTableType === EditableTableType::BY_FIELD) {
                    $dataColumnAttributes[] = "data-name=\"{$column->id}\"";
                    $dataColumnAttributes[] = "data-type=\"{$column->editFieldType->value}\"";
                    $prefix[] = '<span class="table_edit_display field_' . $column->id . '">';
                    $addon[] = '</span>';

                    $dataColumnAttributes[] = 'data-raw_value="'
                        . str_replace(['"', '<', '>'], ['&quot;', '&lt;', '&gt;'], $editValue)
                        . '"';
                } else {
                    $dataColumnClasses[] = "field_{$column->id}";
                }

                // Classes
                if ($isEditable === true && $this->editableTableType === EditableTableType::BY_FIELD) {
                    $dataColumnClasses[] = 'table_edit_field_trigger';
                }
                if (!empty($dataColumnClasses)) {
                    $tmp = Utils::runClosures($dataColumnClasses, [$column, $rowIndex, $rowItem, $columnCount]);
                    $tmp = array_filter($tmp);
                    if (!empty($tmp)) {
                        $tmp = implode(' ', $tmp);
                        $dataColumnAttributes[] = " class=\"{$tmp}\" ";
                    }
                }

                // Attributes
                if (!empty($dataColumnAttributes)) {
                    $tmp = Utils::runClosures($dataColumnAttributes, [$column, $rowIndex, $rowItem, $columnCount]);
                    $tmp = array_filter($tmp);
                    $tmp = implode(' ', $tmp);
                    $dataColumnAttributes = " {$tmp} ";
                }

                // Construct column
                $prefix = Utils::runClosures($prefix, [$column, $rowIndex, $rowItem, $columnCount]);
                $prefix = array_filter($prefix);
                $prefix = implode('', $prefix);
                $addon = Utils::runClosures($addon, [$column, $rowIndex, $rowItem, $columnCount]);
                $addon = array_filter($addon);
                $addon = implode('', $addon);
                $html .= "<td{$dataColumnAttributes}>{$prefix}{$dataValue}{$addon}</td>\n";
            }
            $html .= "</tr>\n";
        }

        return $html;
    }

    public function tableBody(): string
    {
        $columnCount = count($this->tableInstance->columns);
        if (empty($this->tableInstance->rows)) {
            return <<<EOL
            <tr><td colspan="{$columnCount}" class="table-empty table-secondary">No record was found</td></tr>
            EOL;
        }

        $html = '';
        foreach ($this->tableInstance->rows as $rowIndex => $rowItem) {
            if (!empty($this->tableInstance->beforeDataRow)) {
                $tmp = Utils::runClosures(
                    $this->tableInstance->beforeDataRow,
                    [
                        $rowIndex,
                        $rowItem,
                        $columnCount,
                        $this->tableInstance
                    ]
                );
                $html .= implode('', $tmp);
            }

            $html .= $this->htmlDataRow($rowIndex, $rowItem);

            if (!empty($this->tableInstance->afterDataRow)) {
                $tmp = Utils::runClosures(
                    $this->tableInstance->afterDataRow,
                    [
                        $rowIndex,
                        $rowItem,
                        $columnCount,
                        $this->tableInstance
                    ]
                );
                $html .= implode('', $tmp);
            }
        }

        return $html;
    }

    public function rowWithPosition(RowPosition $position, string $title = ''): string
    {
        $html = '';
        if (!empty($this->tableInstance->avgRow) && $this->tableInstance->avgRowPosition === $position) {
            $html .= $this->htmlDataRow(-1, $this->tableInstance->avgRow, 'AVG', ['table-avg-row', 'table-meta-row']);
        }
        if (!empty($this->tableInstance->sumRow) && $this->tableInstance->sumRowPosition === $position) {
            $html .= $this->htmlDataRow(-2, $this->tableInstance->sumRow, 'SUM', ['table-sum-row', 'table-meta-row']);
        }
        if (!empty($this->tableInstance->customRow) && $this->tableInstance->customRowPosition === $position) {
            $html .= $this->htmlDataRow(
                -3,
                $this->tableInstance->customRow,
                'CUSTOM',
                ['table-custom-row', 'table-meta-row']
            );
        }

        return $html;
    }

    public function paginationUrl(string $url, int $page): string
    {
        return str_replace('%pagination', $page, $url);
    }

    public function paginationLinks(): string
    {
        $pagination = &$this->tableInstance->pagination;

        if ($pagination === null) {
            throw new Exception("Pagination is not initialized");
        }

        if ($pagination->pageCount <= 1) {
            return '';
        }

        $url = $pagination->url();
        $pages = '<ul class="pagination">';
        $pages .= '<li class="page-item' . ($pagination->currentPage == 1 ? ' disabled' : '') . '">'
            . '<a class="page-link" href="' . $this->paginationUrl($url, 1) . '">'
            . '<span aria-hidden="true">1</span><span class="sr-only">Previous</span></a></li>';
        $pages .= '<li class="page-item' . ($pagination->currentPage == 1 ? ' disabled' : '') . '">'
            . '<a class="page-link" href="' . $this->paginationUrl($url, $pagination->prevPage) . '">'
            . '<span aria-hidden="true">&laquo;</span><span class="sr-only">Previous</span></a></li>';

        for ($i = $pagination->pagesFrom; $i <= $pagination->pagesTo; ++$i) {
            if ($i === $pagination->currentPage) {
                $pages .= '<li class="page-item active"><a class="page-link" href="'
                    . $this->paginationUrl($url, $i) . '">' . $i . ' <span class="sr-only">(current)</span></a></li>';
            } else {
                $pages .= '<li class="page-item"><a class="page-link" href="'
                    . $this->paginationUrl($url, $i) . '">' . $i . '</a></li>';
            }
        }

        $pages .= '<li class="page-item'
            . ($pagination->currentPage == $pagination->pageCount ? ' disabled' : '') . '">'
            . '<a class="page-link" href="' . $this->paginationUrl($url, $pagination->nextPage) . '">'
            . '<span aria-hidden="true">&raquo;</span><span class="sr-only">Next</span></a></li>';
        $pages .= '<li class="page-item'
            . ($pagination->currentPage == $pagination->pageCount ? ' disabled' : '') . '">'
            . '<a class="page-link" href="' . $this->paginationUrl($url, $pagination->pageCount) . '">'
            . '<span aria-hidden="true">' . $pagination->pageCount . '</span>'
            . '<span class="sr-only">Last</span></a></li>';
        $pages .= '</ul>';

        return $pages;
    }

    // ! OUTPUT
    public function makeOutput(): string
    {
        $classNames = !empty($this->classNames) ? "{$this->classNames}" : '';
        $tableAttributes = [];
        if (!empty($this->tableAttributes)) {
            $tmp = $this->tableAttributes;
            $tmp = Utils::runClosures($tmp, [$this->tableInstance]);
            $tableAttributes = $tmp;
        }

        // Add select options to table from each column that has one of select types
        foreach ($this->tableInstance->columns as $column) {
            if ($column->isEditable === false) {
                continue;
            }

            if (
                $column->editFieldType === FieldType::SELECT
                || $column->editFieldType === FieldType::SELECT_NO_YES
                || $column->editFieldType === FieldType::SELECT_MULTIPLE
            ) {
                $selectOptions = $column->editSelectOptions;
                if ($column->editFieldType == FieldType::SELECT_NO_YES) {
                    $selectOptions = [0 => 'No', 1 => 'Yes'];
                }

                // Options
                if (!empty($selectOptions)) {
                    $tableAttributes[] = 'data-field_' . $column->id . '_options="'
                        . htmlspecialchars(json_encode($selectOptions))
                        . '"';
                }

                // Are they groupped
                if ($column->editSelectOptionsGroupped === true) {
                    $tableAttributes[] = 'data-field_' . $column->id . '_options_groupped="true"';
                }
            }
        }


        $tableAttributes = implode(' ', $tableAttributes);
        $tableAttributes = " {$tableAttributes} ";
        $html = '';
        if ($this->type === TableType::FULL_HTML) {
            $html = <<<EOL
<div class="block block-rounded">
    <div class="block-content block-content-full">
        <div class="table-responsive">
EOL;
        }

        $html .= <<<EOL
        <table id="table_{$this->tableInstance->tableId()}" class="{$classNames}"{$tableAttributes}>
            <thead>
                {$this->rowWithPosition(RowPosition::HEAD_TOP)}
                {$this->titleRow()}
                {$this->filtersRow()}
                {$this->rowWithPosition(RowPosition::HEAD_BOTTOM)}
            </thead>
            <tbody>
                {$this->rowWithPosition(RowPosition::BODY_TOP)}
                {$this->tableBody()}
                {$this->rowWithPosition(RowPosition::BODY_BOTTOM)}
            </tbody>
            <tfoot>
                {$this->rowWithPosition(RowPosition::FOOT_TOP)}
                {$this->rowWithPosition(RowPosition::FOOT_BOTTOM)}
            </tfoot>
        </table>
EOL;

        if ($this->type === TableType::FULL_HTML) {
            $html .= <<<EOL
        </div>
    </div>
    <div class="block-footer">
        {$this->paginationLinks()}
    </div>
</div>
EOL;
        }

        return $html;
    }

    public function showOutput(): void
    {
        header('Content-Type: text/html; charset=utf-8');

        echo $this->makeOutput();
    }
}
