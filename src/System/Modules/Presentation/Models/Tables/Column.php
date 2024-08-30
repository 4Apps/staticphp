<?php

namespace System\Modules\Presentation\Models\Tables;

use System\Modules\Presentation\Models\Tables\Interfaces\ColumnInterface;
use System\Modules\Presentation\Models\Tables\Enums\ColumnType;
use System\Modules\Presentation\Models\Tables\Enums\FilterType;
use System\Modules\Presentation\Models\Tables\Enums\SortDirection;
use System\Modules\Presentation\Models\Tables\Enums\SortNulls;

class Column implements ColumnInterface
{
    // ## Default
    public string $id;
    public string $title = '';
    public string $description = '';
    public ColumnType $type = ColumnType::TEXT;

    // ## Column
    public bool|\Closure $showColumn = true;

    /**
     * Column id is used for field name.
     * Currently only work with Switch type.
     */
    public bool|\Closure $isEditable = false;

    // ## Sort
    public bool $sortEnabled = true;
    public null|string|\Closure $sortBy = null;
    public bool $sortDefaultColumn = false;
    public SortDirection $sortDefaultDirection = SortDirection::ASC;
    public SortNulls $sortNulls = SortNulls::LAST;
    public ?string $sortLinkAttribute = null;

    // ## Filter
    public bool $filterHidden = false;
    public bool $filterEnabled = true;
    public ?string $filterTitle = null;
    public ?string $filterDefaultValue = null;
    public ?string $filterDateValue = null;

    /**
     * Elements can be string or Closure. If its a Closure, column and value are passed as arguments.
     */
    public array $filterInputAttributes = [];
    public array $filterInputClasses = [];

    public ?array $filterSelectOptions = null;
    public ?string $filterSelectOptionsIdKey = null;
    public ?string $filterSelectOptionsTitleKey = null;
    public ?array $filterSelectOptionsGroups = null;
    public ?string $filterSelectOptionsGroupTitleKey = null;
    public bool $filterSelectMultiple = false;
    public bool $filterSelectSkipEmptyDefault = false;
    public bool $filterSelectDefaultDisabled = false;

    public FilterType|\Closure $filterType = FilterType::TEXT;
    public null|string|\Closure $filterBy = null;
    public null|array|\Closure $filterData = null;
    public bool $filterSqlDate = false;

    // ## Data
    public null|\Closure $initValue = null;
    public null|string|\Closure $idKey = null;
    public null|string|\Closure $dataKey = null;
    public null|string|\Closure $editKey = null;
    public array $dataColumnAttributes = [];
    public array $dataColumnClasses = [];
    public string|\Closure $dataColumnPrefix = '';
    public string|\Closure $dataColumnAddon = '';

    // ## Export
    public bool|null|string|\Closure $exportKey = null;

    // ## Presentation
    /**
     * Elements can be string or Closure. If its a Closure, column is passed as the only argument.
     */
    public array $columnAttributes = [];

    /**
     * Elements can be string or Closure. If its a Closure, column is passed as the only argument.
     */
    public array $columnClasses = [];

    /**
     * Wether or not the column is expandable.
     */
    public bool $expandableText = false;

    /**
     * Should html be escaped in a column
     */
    public bool $escapeDataHtml = false;


    public function __construct($id, ...$settings)
    {
        $this->id = $id;
        foreach ($settings as $key => $value) {
            if (property_exists($this, $key) == false) {
                throw new \Exception("\"{$key}\" does not exists on Column");
            }

            $this->{$key} = $value;
        }
    }
}
