<?php
declare(strict_types=1);

namespace Tonyapps\AdvancedReports\Model;

use Magento\Framework\Exception\NotFoundException;

class ReportPool
{
    /**
     * @param array<string, ReportInterface> $reports keyed by report code
     */
    public function __construct(
        private readonly array $reports = []
    ) {
    }

    public function has(string $code): bool
    {
        return isset($this->reports[$code]);
    }

    /**
     * @throws NotFoundException
     */
    public function get(string $code): ReportInterface
    {
        if (!$this->has($code)) {
            throw new NotFoundException(__('Report "%1" does not exist.', $code));
        }

        return $this->reports[$code];
    }

    public function getAclResource(string $code): string
    {
        return 'Tonyapps_AdvancedReports::' . $code;
    }
}
