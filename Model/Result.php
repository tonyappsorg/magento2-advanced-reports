<?php
declare(strict_types=1);

namespace Tonyapps\AdvancedReports\Model;

/**
 * What a report returns; the admin page, CSV, email and REST all render from this.
 */
class Result
{
    public const TYPE_TEXT = 'text';
    public const TYPE_INT = 'int';
    public const TYPE_DECIMAL = 'decimal';
    public const TYPE_CURRENCY = 'currency';
    public const TYPE_PERCENT = 'percent';

    /**
     * @param array<string, array{label: string, type: string}> $columns
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed>|null $totals same keys as a row
     * @param array<string, float|null> $deltas % change of each total vs the previous period
     * @param array<string, mixed>|null $chart Chart.js config ({type, data, options})
     */
    public function __construct(
        public readonly array $columns,
        public readonly array $rows,
        public readonly ?array $totals = null,
        public readonly array $deltas = [],
        public readonly ?array $chart = null
    ) {
    }
}
