<?php
declare(strict_types=1);

namespace Tonyapps\AdvancedReports\Test\Unit\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Mail\MessageInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Tonyapps\AdvancedReports\Model\AttachmentMailer;

class AttachmentMailerTest extends TestCase
{
    public function testTransportWithoutSymfonyMessageFailsCleanlyInsteadOfFatal(): void
    {
        $builder = $this->createMock(TransportBuilder::class);
        foreach (['setTemplateIdentifier', 'setTemplateOptions', 'setTemplateVars', 'setFromByScope', 'addTo'] as $method) {
            $builder->method($method)->willReturnSelf();
        }
        $transport = $this->createMock(TransportInterface::class);
        $transport->method('getMessage')->willReturn($this->createMock(MessageInterface::class));
        $transport->expects(self::never())->method('sendMessage');
        $builder->method('getTransport')->willReturn($transport);
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getDefaultStoreView')->willReturn($store);

        $this->expectException(LocalizedException::class);
        (new AttachmentMailer($builder, $storeManager))->send(['a@example.com'], 'tpl', [], ['a.csv' => 'x']);
    }
}
