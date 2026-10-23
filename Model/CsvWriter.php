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
        fputcsv($handle, array_map([$this, 'cell'], array_column($result->columns, 'label')), ',', '"', '');
        foreach ([...$result->rows, ...($result->totals ? [$result->totals] : [])] as $row) {
            $line = [];
            foreach (array_keys($result->columns) as $key) {
                $line[] = $this->cell($row[$key] ?? '');
            }
            fputcsv($handle, $line, ',', '"', '');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /** Stops spreadsheet apps from evaluating text such as "=HYPERLINK(...)" as a formula. */
    private function cell(mixed $value): string
    {
        $value = (string)$value;

        return !is_numeric($value) && preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }
}
