<?php

namespace System\Modules\Presentation\Models\Tables\Output;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use System\Modules\Presentation\Models\Tables\Enums\ColumnType;
use System\Modules\Presentation\Models\Tables\Interfaces\OutputInterface;
use System\Modules\Presentation\Models\Tables\Traits\TableInstance;

class Excel implements OutputInterface
{
    use TableInstance;

    public string $filename = '';
    public string $author = '';
    public ?\Closure $formatter = null;

    public function makeOutput(): Spreadsheet
    {
        $xls = new Spreadsheet();
        $active_sheet =  $xls->setActiveSheetIndex(0);

        $colNr = 1;
        $rowNr = 1;
        /** @var Column $column */
        foreach ($this->tableInstance->columns as $column) {
            if ($column->exportKey === false || $column->type == ColumnType::ROW_NUMBER) {
                continue;
            }

            $active_sheet->getCell([$colNr, $rowNr])->setValue($column->title);
            $colNr += 1;
        }

        $rowNr = 2;
        foreach ($this->tableInstance->getRows() as $rowIndex => $rowItem) {
            $colNr = 1;
            foreach ($this->tableInstance->columns as $column) {
                if ($column->exportKey === false || $column->type == ColumnType::ROW_NUMBER) {
                    continue;
                }

                $exportKey = $column->exportKey;
                if ($column->exportKey === null) {
                    $exportKey = $column->dataKey;
                }

                $cellValue = is_callable($exportKey) ? $exportKey($column, $rowIndex, $rowItem) : $rowItem[$exportKey];
                $cell = $active_sheet->getCell([$colNr, $rowNr]);

                switch ($column->type) {
                    case 'int':
                    case 'float':
                        $cell->setValueExplicit($cellValue ?? 0, DataType::TYPE_NUMERIC);
                        break;

                    default:
                        $cell->setValueExplicit($cellValue ?? '', DataType::TYPE_STRING);
                        break;
                }
                $colNr += 1;
            }
            $rowNr += 1;
        }

        return $xls;
    }


    public function showOutput(): void
    {
        $xls = $this->makeOutput();
        $xls->getProperties()
            ->setCreator($this->author)
            ->setLastModifiedBy($this->author);

        // Format xls
        if (is_callable($this->formatter)) {
            $formatter = $this->formatter;
            $formatter($xls);
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $this->filename . '.xlsx"');
        header('Cache-Control: max-age=0');
        header('Cache-Control: max-age=1');

        header('Expires: Mon, 26 Jul 1997 05:00:00 GMT'); // Date in the past
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT'); // always modified
        header('Cache-Control: cache, must-revalidate'); // HTTP/1.1
        header('Pragma: public'); // HTTP/1.0

        $objWriter = IOFactory::createWriter($xls, 'Xlsx');
        $objWriter->setPreCalculateFormulas(false);
        $objWriter->save('php://output');
    }
}
