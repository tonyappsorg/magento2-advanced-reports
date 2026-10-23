<?php
declare(strict_types=1);

namespace Tonyapps\AdvancedReports\Controller\Adminhtml\Report;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Exception\LocalizedException;
use Tonyapps\AdvancedReports\Model\Filter;
use Tonyapps\AdvancedReports\Model\FilterFactory;
use Tonyapps\AdvancedReports\Model\ReportPool;

/**
 * ACL is checked per report: the "code" param picks the report and its resource.
 */
abstract class AbstractReport extends Action
{
    public const ADMIN_RESOURCE = 'Tonyapps_AdvancedReports::reports';

    public function __construct(
        Context $context,
        protected readonly ReportPool $reportPool,
        protected readonly FilterFactory $filterFactory
    ) {
        parent::__construct($context);
    }

    protected function _isAllowed(): bool
    {
        $code = $this->getCode();

        return $this->reportPool->has($code)
            && $this->_authorization->isAllowed($this->reportPool->getAclResource($code));
    }

    protected function getCode(): string
    {
        return (string)$this->getRequest()->getParam('code');
    }

    protected function getFilter(): Filter
    {
        try {
            return $this->filterFactory->create($this->getRequest()->getParams());
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());

            return $this->filterFactory->create();
        }
    }
}
