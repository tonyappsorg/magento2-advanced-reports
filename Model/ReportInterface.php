<?php
declare(strict_types=1);

namespace Tonyapps\AdvancedReports\Model;

/**
 * One report. Register it in ReportPool via di.xml under its code, and declare an ACL resource
 * named Tonyapps_AdvancedReports::<code> (or the resource ReportPool is given for it).
 */
interface ReportInterface
{
    public function getTitle(): string;

    /** Whether rows are grouped by the filter's day/week/month period. */
    public function isPeriodic(): bool;

    public function run(Filter $filter): Result;
}
