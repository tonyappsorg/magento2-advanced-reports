<?php
declare(strict_types=1);

namespace Tonyapps\AdvancedReports\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Tonyapps\AdvancedReports\Model\CsvWriter;
use Tonyapps\AdvancedReports\Model\Result;

class CsvWriterTest extends TestCase
{
    public function testKeepsNumbersAndPhonesButGuardsFormulas(): void
    {
        $values = [
            '-5', '+1 555 0100', '+48 (22) 123-45-67', '-12.50',
            '=HYPERLINK("http://x")', '@SUM(A1)', '+cmd|calc', "-2+3+cmd|' /C calc'!A0", 'Plain text',
        ];
        $result = new Result(
            ['v' => ['label' => 'V', 'type' => Result::TYPE_TEXT]],
            array_map(static fn (string $v) => ['v' => $v], $values)
        );

        $lines = array_map(static fn (string $l) => str_getcsv($l, ',', '"', ''), array_slice(explode("\n", trim((new CsvWriter())->write($result))), 1));

        $this->assertSame([
            '-5', '+1 555 0100', '+48 (22) 123-45-67', '-12.50',
            "'=HYPERLINK(\"http://x\")", "'@SUM(A1)", "'+cmd|calc", "'-2+3+cmd|' /C calc'!A0", 'Plain text',
        ], array_column($lines, 0));
    }
}
