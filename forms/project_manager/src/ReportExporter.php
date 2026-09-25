<?php

declare(strict_types=1);

namespace ProjectManager;

final class ReportExporter
{
    /** @param list<array<string, mixed>> $completed @param list<array<string, mixed>> $planned @param list<array<string, mixed>> $other */
    public function export(array $completed, array $planned, array $other, string $completedRange, string $plannedRange): void
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Еженедельный отчёт');
        $sheet->getDefaultRowDimension()->setRowHeight(38);
        foreach (['B' => 20, 'C' => 16, 'D' => 24, 'E' => 42, 'F' => 48, 'G' => 48] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $row = 2;
        $this->writeMainHeader($sheet, $row++);
        $row = $this->writeSection($sheet, $row, 'Итоги за неделю ' . $completedRange, $completed);
        $row = $this->writeSection($sheet, $row, 'План на неделю ' . $plannedRange, $planned);
        $sheet->mergeCells("B{$row}:G{$row}");
        $sheet->setCellValue("B{$row}", 'Список задач (задачи не вошедшие в списки выше)');
        $this->styleSectionTitle($sheet, $row++);
        $headers = ['Задача', 'Дата создания', 'Ожидаемая дата выполнения (если заполнена)', 'Статус задачи', 'Краткое описание', 'Комментарий'];
        $this->writeRow($sheet, $row, $headers);
        $this->styleHeader($sheet, $row++);
        foreach ($other as $item) {
            $this->writeRow($sheet, $row, [
                $item['task'] ?? '', $item['created'] ?? '', '', $item['status'] ?? '', $item['description'] ?? '', '',
            ]);
            $this->formatDateCell($sheet, $row++);
        }

        $lastRow = max(2, $row - 1);
        $sheet->getStyle("B2:G{$lastRow}")->getAlignment()->setWrapText(true)->setVertical('top');
        $sheet->getStyle("B2:G{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle('thin')->getColor()->setARGB('FFD9D9D9');
        $sheet->freezePane('B3');
        $sheet->setAutoFilter("B2:G2");

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="weekly-report.xlsx"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        $writer->setUseDiskCaching(true, sys_get_temp_dir());
        $writer->save('php://output');
        $spreadsheet->disconnectWorksheets();
    }

    private function writeMainHeader(object $sheet, int $row): void
    {
        $this->writeRow($sheet, $row, ['Задача', 'Дата создания', 'ИС', 'Краткое описание', 'Планируемые действия', 'Итог']);
        $this->styleHeader($sheet, $row);
    }

    /** @param list<array<string, mixed>> $items */
    private function writeSection(object $sheet, int $row, string $title, array $items): int
    {
        $sheet->mergeCells("B{$row}:G{$row}");
        $sheet->setCellValue("B{$row}", $title);
        $this->styleSectionTitle($sheet, $row++);
        foreach ($items as $item) {
            $this->writeRow($sheet, $row, [
                $item['task'] ?? '', $item['created'] ?? '', $item['system'] ?? '', $item['description'] ?? '',
                $item['actions'] ?? '', $item['result'] ?? '',
            ]);
            $this->formatDateCell($sheet, $row++);
        }
        return $row;
    }

    /** @param list<mixed> $values */
    private function writeRow(object $sheet, int $row, array $values): void
    {
        foreach ($values as $offset => $value) {
            $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($offset + 2) . $row;
            $sheet->setCellValueExplicit($cell, (string) $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }
    }

    private function styleHeader(object $sheet, int $row): void
    {
        $style = $sheet->getStyle("B{$row}:G{$row}");
        $style->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $style->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF4472C4');
        $style->getAlignment()->setHorizontal('center')->setVertical('center')->setWrapText(true);
    }

    private function styleSectionTitle(object $sheet, int $row): void
    {
        $style = $sheet->getStyle("B{$row}:G{$row}");
        $style->getFont()->setBold(true)->getColor()->setARGB('FF203864');
        $style->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9EAF7');
        $style->getAlignment()->setHorizontal('center')->setVertical('center');
    }

    private function formatDateCell(object $sheet, int $row): void
    {
        $cell = "C{$row}";
        $value = trim((string) $sheet->getCell($cell)->getValue());
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return;
        }
        $timestamp = strtotime($value . ' UTC');
        if ($timestamp === false) {
            return;
        }
        $sheet->setCellValueExplicit($cell, $timestamp / 86400 + 25569, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('dd.mm.yyyy');
    }
}
