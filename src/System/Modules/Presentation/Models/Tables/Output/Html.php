<?php

namespace System\Modules\Presentation\Models\Tables\Output;

use Exception;
use System\Modules\Presentation\Models\Tables\Interfaces\OutputInterface;
use System\Modules\Presentation\Models\Tables\Enums\TableType;
use System\Modules\Presentation\Models\Tables\Enums\ColumnType;
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

    /**
     * Elements can be string or Closure. If its a Closure, row index and row data are passed as arguments.
     */
    public array $dataRowAttributes = [];
    public array $dataRowClasses = ['data-row'];


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
    public function filterInputValue(string $field, ?string $compare = null): string
    {
        $parsedData = $this->tableInstance->filter->parsedData();

        if ($compare !== null) {
            if (isset($parsedData[$field]['value'])) {
                if (is_array($parsedData[$field]['value'])) {
                    if (in_array($compare, $parsedData[$field]['value'])) {
                        return ' selected="selected"';
                    }
                } elseif ($parsedData[$field]['value'] === $compare) {
                    return ' selected="selected"';
                }
            }

            return '';
        }

        $attributes = '';
        if (isset($parsedData[$field])) {
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
        switch ($forColumn->type) {
            case ColumnType::DATE:
            case ColumnType::DATETIME:
            case ColumnType::DATEINTERVAL:
                if ($forColumn->type == ColumnType::DATE) {
                    $classes .= ' datepicker-trigger';
                } elseif ($forColumn->type == ColumnType::DATETIME) {
                    $classes .= ' datetimepicker-trigger';
                } elseif ($forColumn->type == ColumnType::DATEINTERVAL) {
                    $classes .= ' dateintervalpicker-trigger';
                }

                if (!empty($forColumn->filterDateValue)) {
                    $attributes .= ' data-unix="' . $forColumn->filterDateValue . '"';
                }

                // no break

            case ColumnType::MULTILINE_TEXT:
            case ColumnType::TEXT:
                $html = '<input type="text" class="' . $classes . '"' . $attributes;
                if (!empty($forColumn->filterTitle)) {
                    $html .= ' placeholder="' . $forColumn->filterTitle . '" ';
                }
                $html .= ' ' . $this->filterInputValue($forColumn->id) . '>';
                break;

            case ColumnType::SELECT:
            case ColumnType::SELECT_MULTIPLE:
                if (
                    isset($forColumn->filterSelectOptions) == false
                    || is_array($forColumn->filterSelectOptions) === false
                ) {
                    throw new \Exception("Value for {$forColumn->id} should be [key => value] array");
                }
                if ($forColumn->type == ColumnType::SELECT_MULTIPLE) {
                    $attributes .= ' multiple="multiple" size="3" ';
                }

                $parsedData = $this->tableInstance->filter->parsedData();
                $classes .= ' form-select form-select-sm';
                $html = '<select class="' . $classes . '"' . $attributes . '>';
                if (count($forColumn->filterSelectOptions) == 0 && isset($parsedData[$forColumn->id])) {
                    $html .= '<option value="' . $parsedData[$forColumn->id]['value'] . '">'
                        . $parsedData[$forColumn->id]['title'] . '</option>';
                } elseif (empty($forColumn->filterSelectSkipEmptyDefault)) {
                    $html .= '<option value=""'
                        . (!empty($forColumn->filterSelectDefaultDisabled) ? ' disabled="disabled"' : '') . '>'
                        . ($forColumn->filterTitle ?? '')
                        . '</option>';
                }
                if (!empty($forColumn->filterSelectOptionsGroups) && is_array($forColumn->filterSelectOptionsGroups)) {
                    foreach ($forColumn->filterSelectOptionsGroups as $gkey => $gitem) {
                        $final_optgroup_title = (empty($forColumn->filterSelectOptionsGroupTitleKey)
                            ? $gitem
                            : $gitem[$forColumn->filterSelectOptionsGroupTitleKey]
                        );
                        $html .= '<optgroup label="' . $final_optgroup_title . '">';

                        if (isset($forColumn->filterSelectOptions[$gkey])) {
                            foreach ($forColumn->filterSelectOptions[$gkey] as $key => $item) {
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
                    foreach ($forColumn->filterSelectOptions as $key => $item) {
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

            case ColumnType::SELECT_ALL_CHECKBOX:
                $id = 'parent_checkbox_' . $this->tableInstance->tableId();
                $html = '<input type="checkbox" class="faCheckbox parent_checkbox" id="' . $id . '">'
                    . '<label for="' . $id . '"></label>';
                break;

            case ColumnType::SWITCH:
                $options = [0 => 'No', 1 => 'Yes'];

                $parsedData = $this->tableInstance->filter->parsedData();
                $classes .= ' form-select form-select-sm';
                $html = '<select class="' . $classes . '"' . $attributes . '>';
                if (count($options) == 0 && isset($parsedData[$forColumn->id])) {
                    $html .= '<option value="' . $parsedData[$forColumn->id]['value'] . '">'
                        . $parsedData[$forColumn->id]['title']
                        . '</option>';
                } elseif (empty($forColumn->filterSelectSkipEmptyDefault)) {
                    $html .= '<option value=""'
                        . (!empty($forColumn->filterSelectDefaultDisabled) ? ' disabled="disabled"' : '')
                        . '>'
                        . ($forColumn->filterTitle ?? '')
                        . '</option>';
                }

                foreach ($options as $key => $item) {
                    $html .= '<option value="' . $key . '"'
                        . $this->filterInputValue($forColumn->id, $key) . '>'
                        . $item
                        . '</option>';
                }

                $html .= '</select>';
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
    public function htmlDataRow(int $rowIndex, array $rowItem, string $title = ''): string
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
                $tmp = implode(' ', $tmp);
                $attributes .= " {$tmp} ";
            }

            // Classes
            if (!empty($this->dataRowClasses)) {
                $tmp = $this->dataRowClasses;
                $tmp = Utils::runClosures($tmp, [$rowIndex, $rowItem, $columnCount]);
                $tmp = implode(' ', $tmp);
                $attributes .= " class=\"{$tmp}\" ";
            }

            $html = '<tr title="' . $title . '"' . $attributes . '>';
            foreach ($this->tableInstance->columns as $column) {
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

                $dataValue = '';
                if (is_callable($column->dataKey)) {
                    $dataKey = $column->dataKey;
                    $dataValue = $dataKey($column, $rowIndex, $rowItem, $columnCount);
                } elseif (isset($rowItem[$column->dataKey])) {
                    $dataValue = $rowItem[$column->dataKey];
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

                // Override data value with edit key if its present
                if ($isEditable === true && !empty($column->editKey)) {
                    if (is_callable($column->editKey)) {
                        $editKey = $column->editKey;
                        $dataValue = $editKey($column, $rowIndex, $rowItem, $columnCount);
                    } elseif (isset($rowItem[$column->editKey])) {
                        $dataValue = $rowItem[$column->editKey];
                    }
                }

                switch ($column->type) {
                    case ColumnType::ROW_NUMBER:
                        $column->dataColumnClasses[] = 'text-center col-md-c-1';
                        if ($rowIndex == -1) {
                            return '';
                        }

                        $number = $rowIndex + 1;
                        $dataValue = "{$number}.";
                        break;

                    case ColumnType::SWITCH:
                        // Checked
                        $checked = $dataValue == 1 ? ' checked="checked"' : '';

                        $classes = '';
                        $disabled = ' disabled="disabled" ';
                        if ($isEditable === true) {
                            $classes = ' update_field ';
                            $disabled = '';
                        }

                        $dataValue = <<<EOL
                            <div class="form-check form-switch">
                                <input
                                    class="form-check-input{$classes}"
                                    name="{$column->id}"
                                    id="{$column->id}_{$idValue}"
                                    type="checkbox"
                                    {$checked}
                                    {$disabled}
                                >
                                <label class="form-check-label" for="{$column->id}_{$idValue}"></label>
                            </div>
                        EOL;
                        break;

                    case ColumnType::SELECT_ALL_CHECKBOX:
                        $column->dataColumnClasses[] = 'text-center col-md-c-1';
                        $dataValue = <<<EOL
                            <label class="form-colorinput form-colorinput-light">
                                <input type="checkbox" class="form-colorinput-input child_checkbox"
                                    id="parent_checkbox_{$idValue}" data-id="{$idValue}">
                                <span class="form-colorinput-color bg-white" for="parent_checkbox_{$idValue}"></span>
                            </label>
                        EOL;
                        break;

                    case ColumnType::SELECT:
                        if ($isEditable === false) {
                            break;
                        }

                        $classes = 'form-control input-xs update_field';
                        $selectField = "<select class=\"{$classes}\" name=\"{$column->id}\""
                            . " id=\"{$column->id}_{$idValue}\">";
                        foreach ($column->filterSelectOptions as $key => $item) {
                            $finalId = (empty($column->filterSelectOptionsIdKey)
                                ? $key
                                : $item[$column->filterSelectOptionsIdKey]
                            );
                            $finalTitle = (empty($column->filterSelectOptionsTitleKey)
                                ? $item
                                : $item[$column->filterSelectOptionsTitleKey]
                            );
                            $selected = self::inputValue($dataValue, $key);
                            $selectField .= "<option value=\"{$finalId}\" {$selected}>{$finalTitle}</option>";
                        }
                        $selectField .= '</select>';
                        $dataValue = $selectField;
                        break;

                    case ColumnType::MULTILINE_TEXT:
                        if ($isEditable === false) {
                            break;
                        }

                        $dataValue = str_replace(['<', '>'], ['&lt;', '&gt;'], $dataValue);
                        $dataValue = <<<EOL
                            <textarea
                                class="form-control input-xs update_field"
                                name="{$column->id}"
                                id=\"{$column->id}_{$idValue}\"
                                rows="2"
                            >{$dataValue}</textarea>
                        EOL;
                        break;

                    default:
                        if ($isEditable === false) {
                            break;
                        }

                        $classes = '';
                        if (
                            in_array($column->type, [ColumnType::DATE, ColumnType::DATEINTERVAL, ColumnType::DATETIME])
                        ) {
                            $classes = ' datepicker-trigger';
                        }

                        $dataValue = self::inputValue($dataValue);
                        $dataValue = <<<EOL
                            <input
                                type="text"
                                class="form-control input-xs update_field{$classes}"
                                name="{$column->id}"
                                id=\"{$column->id}_{$idValue}\"
                                {$dataValue}>
                        EOL;
                        break;
                }

                // Escape HTML
                if ($isEditable === false && $column->escapeDataHtml === true) {
                    $dataValue = str_replace(['<', '>'], ['&lt;', '&gt;'], $dataValue);
                }

                // Expandable Text
                if (!empty($column->expandableText)) {
                    if (!empty($column->dataColumnClasses)) {
                        $column->dataColumnClasses[] = 'text-cell';
                    } else {
                        $column->dataColumnClasses = ['text-cell'];
                    }

                    $dataValue = <<<EOL
                        <div class="truncated-text">{$dataValue}</div>
                        <div class="expand-switch">Expand</div>
                    EOL;
                }

                // Attributes
                $attributes = '';
                if (!empty($column->dataColumnAttributes)) {
                    $tmp = $column->dataColumnAttributes;
                    $tmp = Utils::runClosures($tmp, [$column, $rowIndex, $rowItem, $columnCount]);
                    $attributes .= implode(' ', $tmp);
                    $attributes = " {$attributes} ";
                }

                // Classes
                if (!empty($column->dataColumnClasses)) {
                    $tmp = $column->dataColumnClasses;
                    $tmp = Utils::runClosures($tmp, [$column, $rowIndex, $rowItem, $columnCount]);
                    $tmp = implode(' ', $tmp);
                    $attributes .= " class=\"{$tmp}\" ";
                }

                // Construct column
                $prefix = Utils::expandClosure($column->dataColumnPrefix, [$column, $rowIndex, $rowItem, $columnCount]);
                $addon = Utils::expandClosure($column->dataColumnAddon, [$column, $rowIndex, $rowItem, $columnCount]);
                $html .= "<td{$attributes}>{$prefix}{$dataValue}{$addon}</td>\n";
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
            $html .= $this->htmlDataRow(-1, $this->tableInstance->avgRow, 'AVG');
        }
        if (!empty($this->tableInstance->sumRow) && $this->tableInstance->sumRowPosition === $position) {
            $html .= $this->htmlDataRow(-2, $this->tableInstance->sumRow, 'SUM');
        }
        if (!empty($this->tableInstance->customRow) && $this->tableInstance->customRowPosition === $position) {
            $html .= $this->htmlDataRow(-3, $this->tableInstance->customRow);
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
        $html = '';
        if ($this->type === TableType::FULL_HTML) {
            $html = <<<EOL
<div class="block block-rounded">
    <div class="block-content block-content-full">
        <div class="table-responsive">
EOL;
        }

        $html .= <<<EOL
        <table class="{$classNames}" id="table_{$this->tableInstance->tableId()}">
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
