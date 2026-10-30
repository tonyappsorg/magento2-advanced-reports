<?php
declare(strict_types=1);

namespace Tonyapps\AdvancedReports\Test\Unit\Block\Adminhtml;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use PHPUnit\Framework\TestCase;
use Tonyapps\AdvancedReports\Block\Adminhtml\Report;
use Tonyapps\AdvancedReports\Model\Result;

class ReportTest extends TestCase
{
    public function testRowAmountsUseTheRowsOwnBaseCurrency(): void
    {
        $priceCurrency = $this->createMock(PriceCurrencyInterface::class);
        $priceCurrency->method('format')->willReturnCallback(
            static fn ($amount, $includeContainer, $precision, $scope, $currency) => $currency . ' ' . $amount
        );
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn('USD');

        $block = (new \ReflectionClass(Report::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(Report::class, 'priceCurrency'))->setValue($block, $priceCurrency);
        (new \ReflectionProperty(Report::class, '_scopeConfig'))->setValue($block, $scopeConfig);

        self::assertSame('EUR 5', $block->format(Result::TYPE_CURRENCY, 5, 'EUR'));
        self::assertSame('USD 5', $block->format(Result::TYPE_CURRENCY, 5));
    }
}
