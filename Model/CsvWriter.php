<?php
declare(strict_types=1);

namespace Tonyapps\AdvancedReports\Model;

/**
 * Raw (unformatted) values, header = column labels, totals as the last row.
 */
class CsvWriter
{
    public function write(Result $result): string
    {
        $handle = fopen('php://temp', 'r+');
        $this->writeTo($handle, $result->columns, $result->rows, $result->totals);
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * @param resource $handle
     * @param array<string, array{label: string, type: string}> $columns
     * @param iterable<array<string, mixed>> $rows
     * @param array<string, mixed>|null $totals
     */
    public function writeTo($handle, array $columns, iterable $rows, ?array $totals = null): void
    {
        fputcsv($handle, array_map([$this, 'cell'], array_column($columns, 'label')), ',', '"', '');
        foreach ($rows as $row) {
            $this->writeRow($handle, $columns, $row);
        }
        if ($totals) {
            $this->writeRow($handle, $columns, $totals);
        }
    }

    /** @param resource $handle */
    private function writeRow($handle, array $columns, array $row): void
    {
        $line = [];
        foreach (array_keys($columns) as $key) {
            $line[] = $this->cell($row[$key] ?? '');
        }
        fputcsv($handle, $line, ',', '"', '');
    }

    /**
     * Stops spreadsheet apps from evaluating text such as "=HYPERLINK(...)" as a formula. Numbers and
     * phone-like values ("+1 555 0100", "-5", "+48 (22) 123-45-67") pass through untouched so ERP imports
     * get the real value; a leading + or - followed by anything else is still guarded.
     */
    private function cell(mixed $value): string
    {
        $value = (string)$value;
        if (is_numeric($value) || preg_match('/^[+\-][\d\s().\/-]*$/', $value)) {
            return $value;
        }

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }
}
