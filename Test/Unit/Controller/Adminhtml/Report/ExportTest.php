<?php
declare(strict_types=1);

namespace Tonyapps\AdvancedReports\Test\Unit\Controller\Adminhtml\Report;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use PHPUnit\Framework\TestCase;
use Tonyapps\AdvancedReports\Controller\Adminhtml\Report\Export;
use Tonyapps\AdvancedReports\Model\CsvWriter;
use Tonyapps\AdvancedReports\Model\Filter;
use Tonyapps\AdvancedReports\Model\FilterFactory;
use Tonyapps\AdvancedReports\Model\Report\OrderExport;
use Tonyapps\AdvancedReports\Model\ReportPool;

class ExportTest extends TestCase
{
    public function testOrderExportIsStreamedToATempFileThatIsSentAndRemoved(): void
    {
        $dir = sys_get_temp_dir() . '/tonyapps_export_test_' . bin2hex(random_bytes(4));
        mkdir($dir . '/export', 0777, true);

        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->with('code')->willReturn('order_export');
        $request->method('getParams')->willReturn([]);
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);

        $tz = new \DateTimeZone('UTC');
        $filterFactory = $this->createMock(FilterFactory::class);
        $filterFactory->method('create')->willReturn(
            new Filter(new \DateTimeImmutable('2026-09-01', $tz), new \DateTimeImmutable('2026-09-30', $tz))
        );
        $report = $this->createMock(OrderExport::class);
        $report->method('getColumns')->willReturn(['increment_id' => ['label' => 'Order #', 'type' => 'text']]);
        $report->method('stream')->willReturnCallback(static function () {
            yield ['increment_id' => '000000041'];
        });
        $report->expects(self::never())->method('run');
        $pool = $this->createMock(ReportPool::class);
        $pool->method('get')->willReturn($report);

        $directory = $this->createMock(WriteInterface::class);
        $directory->method('getAbsolutePath')->willReturnCallback(static fn (string $path) => $dir . '/' . $path);
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->with(DirectoryList::VAR_DIR)->willReturn($directory);

        $response = $this->createMock(ResponseInterface::class);
        $written = null;
        $fileFactory = $this->createMock(FileFactory::class);
        $fileFactory->expects(self::once())->method('create')
            ->with(
                'order_export_20260901_20260930.csv',
                self::callback(function (array $content) use ($dir, &$written) {
                    $written = file_get_contents($dir . '/' . $content['value']);
                    return $content['type'] === 'filename' && $content['rm'] === true;
                }),
                DirectoryList::VAR_DIR
            )
            ->willReturn($response);

        $controller = new Export($context, $pool, $filterFactory, $fileFactory, $filesystem, new CsvWriter());

        self::assertSame($response, $controller->execute());
        self::assertSame("\"Order #\"\n000000041\n", $written);
        array_map('unlink', glob($dir . '/export/*'));
        rmdir($dir . '/export');
        rmdir($dir);
    }
}
