<?php
declare(strict_types=1);

namespace Tonyapps\AdvancedReports\Model;

use Magento\Framework\Exception\NotFoundException;

class ReportPool
{
    /**
     * @param array<string, ReportInterface> $reports keyed by report code
     * @param array<string, string> $aclResources optional per-code override of the ACL resource
     */
    public function __construct(
        private readonly array $reports = [],
        private readonly array $aclResources = []
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

    /**
     * @return array<string, ReportInterface>
     */
    public function getAll(): array
    {
        return $this->reports;
    }

    public function getAclResource(string $code): string
    {
        return $this->aclResources[$code] ?? 'Tonyapps_AdvancedReports::' . $code;
    }
}
