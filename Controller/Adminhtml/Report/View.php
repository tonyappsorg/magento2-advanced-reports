<?php
declare(strict_types=1);

namespace Tonyapps\AdvancedReports\Controller\Adminhtml\Report;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;
use Tonyapps\AdvancedReports\Model\FilterFactory;
use Tonyapps\AdvancedReports\Model\ReportPool;

class View extends AbstractReport implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        ReportPool $reportPool,
        FilterFactory $filterFactory,
        private readonly PageFactory $pageFactory
    ) {
        parent::__construct($context, $reportPool, $filterFactory);
    }

    public function execute(): Page
    {
        $code = $this->getCode();
        $report = $this->reportPool->get($code);

        /** @var Page $page */
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Tonyapps_AdvancedReports::' . $code);
        $page->getConfig()->getTitle()->prepend($report->getTitle());
        $page->getLayout()->getBlock('tonyapps.report')
            ->setData('code', $code)
            ->setData('report', $report)
            ->setData('filter', $this->getFilter());

        return $page;
    }
}
