<?php
declare(strict_types=1);

namespace Tonyapps\AdvancedReports\Controller\Adminhtml\Report;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Filesystem;
use Tonyapps\AdvancedReports\Model\CsvWriter;
use Tonyapps\AdvancedReports\Model\FilterFactory;
use Tonyapps\AdvancedReports\Model\Report\AbstractOrderExport;
use Tonyapps\AdvancedReports\Model\ReportPool;

/**
 * Writes the CSV to a temp file under var/ and streams it back (the file is removed after sending), so a long
 * order export never sits in memory as one string.
 */
class Export extends AbstractReport implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        ReportPool $reportPool,
        FilterFactory $filterFactory,
        private readonly FileFactory $fileFactory,
        private readonly Filesystem $filesystem,
        private readonly CsvWriter $csvWriter
    ) {
        parent::__construct($context, $reportPool, $filterFactory);
    }

    public function execute(): ResponseInterface
    {
        $code = $this->getCode();
        $filter = $this->getFilter();
        $report = $this->reportPool->get($code);
        $name = sprintf('%s_%s_%s.csv', $code, $filter->from->format('Ymd'), $filter->to->format('Ymd'));

        $directory = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $path = 'export/tonyapps_advancedreports_' . bin2hex(random_bytes(8)) . '.csv';
        $directory->create('export');
        $handle = fopen($directory->getAbsolutePath($path), 'w');
        try {
            if ($report instanceof AbstractOrderExport) {
                $this->csvWriter->writeTo($handle, $report->getColumns(), $report->stream($filter));
            } else {
                $result = $report->run($filter);
                $this->csvWriter->writeTo($handle, $result->columns, $result->rows, $result->totals);
            }
        } finally {
            fclose($handle);
        }

        return $this->fileFactory->create(
            $name,
            ['type' => 'filename', 'value' => $path, 'rm' => true],
            DirectoryList::VAR_DIR,
            'text/csv; charset=utf-8'
        );
    }
}
