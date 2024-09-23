<?php

namespace System\Modules\Presentation\Models\Tables;

use System\Modules\Presentation\Models\Tables\Interfaces\PaginationInterface;
use System\Modules\Presentation\Models\Tables\Interfaces\TableInterface;

/**
 * Html pages model, for quick record paging.
 */
class Pagination implements PaginationInterface
{
    /**
     * Table instance
     *
     * (default value: '')
     *
     * @var Table
     * @access protected
     */
    protected Table $tableInstance;


    /**
     * Filter url prefix
     *
     * (default value: '')
     *
     * @var string
     * @access protected
     */
    protected string $urlPrefix = '';

    /**
     * Start limit from this number - use this in SQL query
     *
     * (default value: '')
     *
     * @var string
     * @access public
     */
    public int $limitFrom = 0;

    /**
     * Per page limit - this is how many records to show on a page
     *
     * (default value: '')
     *
     * @var string
     * @access public
     */
    public int $limitPerPage = 50;

    public int $pagesToShow = 10;
    public int $recordCount = 10;
    public int $currentPage = 0;
    public int $pageCount = 0;
    public int $nextPage = 0;
    public int $prevPage = 0;
    public int $pagesFrom = 0;
    public int $pagesTo = 0;


    /**
     * Construct tableFilters
     *
     * @access public
     * @param  Table  $tableInstance
     * @param  string $url_prefix (default: [empty string])
     * @return void
     */
    public function __construct(
        TableInterface &$tableInstance,
        string $urlPrefix = '',
        int $currentPage = 1,
        int $limitPerPage = 50,
        int $pagesToShow = 10
    ) {
        $this->tableInstance = &$tableInstance;
        $this->urlPrefix = $urlPrefix;
        $this->currentPage = $currentPage;
        $this->limitPerPage = $limitPerPage;
        $this->pagesToShow = $pagesToShow;
    }

    public function url(): string
    {
        if ($this->urlPrefix === null) {
            return '';
        }

        return (strpos($this->urlPrefix, '%pagination') === false ?
            $this->urlPrefix . '%pagination' :
            $this->urlPrefix
        );
    }

    public function calculate(int $recordCount, ?int $currentPage = null): void
    {
        $pages_left = (int) floor($this->pagesToShow / 2);
        $pages_right = $this->pagesToShow - $pages_left - 1;

        $this->recordCount = $recordCount;
        if ($currentPage !== null) {
            $this->currentPage = $currentPage;
        }

        $this->pageCount = (int) ceil($this->recordCount / $this->limitPerPage);
        if (empty($this->currentPage) || $this->currentPage > $this->pageCount) {
            $this->currentPage = 1;
        }
        $this->limitFrom = ($this->currentPage < 1 ? 0 : ($this->currentPage - 1) * $this->limitPerPage);

        $this->nextPage = ($this->currentPage + 1 > $this->pageCount ? false : $this->currentPage + 1);
        $this->prevPage = ($this->currentPage - 1 < 1 ? false : $this->currentPage - 1);

        switch (true) {
            case ($this->currentPage - $pages_left < 1):
                $this->pagesFrom = 1;
                $this->pagesTo = ($this->currentPage
                    + $this->pagesToShow >= $this->pageCount ? $this->pageCount : $this->currentPage
                    + ($this->pagesToShow - $this->currentPage)
                );
                break;

            case ($this->currentPage + $pages_right >= $this->pageCount):
                $this->pagesFrom = (int) ($this->currentPage
                    - $this->pagesToShow <= 0 ? 1 : $this->currentPage
                    - ($this->pagesToShow - ($this->pageCount - $this->currentPage) - 1)
                );
                $this->pagesTo = $this->pageCount;
                break;

            default:
                $this->pagesFrom = $this->currentPage - $pages_left;
                $this->pagesTo = $this->currentPage + $pages_right;
                break;
        }
    }
}
