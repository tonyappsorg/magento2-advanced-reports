<?php
declare(strict_types=1);

namespace Tonyapps\AdvancedReports\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Tonyapps\AdvancedReports\Model\Filter;

class FilterTest extends TestCase
{
    private function filter(string $from, string $to, string $period = Filter::PERIOD_DAY): Filter
    {
        $tz = new \DateTimeZone('America/New_York');

        return new Filter(new \DateTimeImmutable($from, $tz), new \DateTimeImmutable($to, $tz), $period);
    }

    public function testUtcRangeCoversWholeLocalDaysAcrossDst(): void
    {
        // US DST starts 2026-03-08: that local day is 23 hours long.
        self::assertSame(
            ['2026-03-08 05:00:00', '2026-03-09 04:00:00'],
            $this->filter('2026-03-08', '2026-03-08')->getUtcRange()
        );
    }

    public function testPreviousPeriodHasSameLengthAndEndsTheDayBefore(): void
    {
        $previous = $this->filter('2026-09-01', '2026-09-30')->getPrevious();

        self::assertSame('2026-08-02', $previous->from->format('Y-m-d'));
        self::assertSame('2026-08-31', $previous->to->format('Y-m-d'));
    }

    public function testUtcHourIsBucketedIntoLocalDay(): void
    {
        $filter = $this->filter('2026-09-01', '2026-09-30');

        // 03:00 UTC is 23:00 the previous evening in New York (EDT, UTC-4).
        self::assertSame('2026-09-09', $filter->getBucket('2026-09-10 03'));
        self::assertSame('2026-09-10', $filter->getBucket('2026-09-10 04'));
    }

    public function testWeekAndMonthBuckets(): void
    {
        $weeks = $this->filter('2026-09-01', '2026-09-14', Filter::PERIOD_WEEK);
        // 2026-09-01 is a Tuesday; weeks are labelled by their Monday.
        self::assertSame(['2026-08-31', '2026-09-07', '2026-09-14'], $weeks->getBuckets());
        self::assertSame('2026-09-07', $weeks->getBucket('2026-09-13 20'));

        $months = $this->filter('2026-08-15', '2026-10-01', Filter::PERIOD_MONTH);
        self::assertSame(['2026-08', '2026-09', '2026-10'], $months->getBuckets());
    }
}
