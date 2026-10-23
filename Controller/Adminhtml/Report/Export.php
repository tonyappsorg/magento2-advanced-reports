<?php
declare(strict_types=1);

namespace Tonyapps\AdvancedReports\Controller\Adminhtml\Report;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Tonyapps\AdvancedReports\Model\CsvWriter;
use Tonyapps\AdvancedReports\Model\FilterFactory;
use Tonyapps\AdvancedReports\Model\ReportPool;

class Export extends AbstractReport implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        ReportPool $reportPool,
        FilterFactory $filterFactory,
        private readonly RawFactory $rawFactory,
        private readonly CsvWriter $csvWriter
    ) {
        parent::__construct($context, $reportPool, $filterFactory);
    }

    public function execute(): Raw
    {
        $code = $this->getCode();
        $filter = $this->getFilter();
        $csv = $this->csvWriter->write($this->reportPool->get($code)->run($filter));
        $name = sprintf('%s_%s_%s.csv', $code, $filter->from->format('Ymd'), $filter->to->format('Ymd'));

        return $this->rawFactory->create()
            ->setHeader('Content-Type', 'text/csv; charset=utf-8', true)
            ->setHeader('Content-Disposition', 'attachment; filename="' . $name . '"', true)
            ->setContents($csv);
    }
}
