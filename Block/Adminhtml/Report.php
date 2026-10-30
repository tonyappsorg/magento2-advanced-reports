<?php
declare(strict_types=1);

namespace Tonyapps\AdvancedReports\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Directory\Model\Currency;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Model\Order\Config as OrderConfig;
use Tonyapps\AdvancedReports\Model\Filter;
use Tonyapps\AdvancedReports\Model\ReportInterface;
use Tonyapps\AdvancedReports\Model\Result;

/**
 * Filter form, chart and table for whichever report the View controller put on the block.
 */
class Report extends Template
{
    /** Row-level reports (order export) can be long; the CSV always has every row. */
    public const MAX_HTML_ROWS = 500;

    private ?Result $result = null;

    public function __construct(
        Context $context,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly OrderConfig $orderConfig,
        private readonly Json $json,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getReport(): ReportInterface
    {
        return $this->getData('report');
    }

    public function getFilter(): Filter
    {
        return $this->getData('filter');
    }

    public function getResult(): Result
    {
        // One row past the page size, so the template knows to say "showing the first N" without counting all.
        $filter = $this->getFilter();
        return $this->result ??= $this->getReport()->run(new Filter(
            $filter->from,
            $filter->to,
            $filter->period,
            $filter->storeIds,
            $filter->states,
            self::MAX_HTML_ROWS + 1
        ));
    }

    public function getFormUrl(): string
    {
        return $this->getUrl('*/*/view', ['code' => $this->getData('code')]);
    }

    public function getExportUrl(): string
    {
        return $this->getUrl('*/*/export', ['code' => $this->getData('code'), '_query' => $this->getFilterParams()]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getFilterParams(): array
    {
        $filter = $this->getFilter();

        return [
            'from' => $filter->from->format('Y-m-d'),
            'to' => $filter->to->format('Y-m-d'),
            'period' => $filter->period,
            'store_ids' => $filter->storeIds,
            'states' => $filter->states,
        ];
    }

    /**
     * @return array<int, string> store view id => "Website / Store / View"
     */
    public function getStoreOptions(): array
    {
        $options = [];
        foreach ($this->_storeManager->getStores() as $store) {
            $options[(int)$store->getId()] = $store->getWebsite()->getName() . ' / '
                . $store->getGroup()->getName() . ' / ' . $store->getName();
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public function getStateOptions(): array
    {
        return array_map('strval', $this->orderConfig->getStates());
    }

    public function getChartConfig(): ?string
    {
        $chart = $this->getResult()->chart;
        if ($chart === null) {
            return null;
        }
        $chart['options'] = ($chart['options'] ?? []) + ['responsive' => true, 'maintainAspectRatio' => false];

        return $this->json->serialize($chart);
    }

    /** @param string|null $currency the row's own base currency (row-level reports); default: global base */
    public function format(string $type, mixed $value, ?string $currency = null): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return match ($type) {
            Result::TYPE_CURRENCY => $this->priceCurrency->format(
                (float)$value,
                false,
                PriceCurrencyInterface::DEFAULT_PRECISION,
                null,
                $currency ?: $this->_scopeConfig->getValue(Currency::XML_PATH_CURRENCY_BASE)
            ),
            Result::TYPE_INT => number_format((float)$value),
            Result::TYPE_DECIMAL => number_format((float)$value, 2),
            Result::TYPE_PERCENT => number_format((float)$value, 1) . '%',
            default => (string)$value,
        };
    }
}
