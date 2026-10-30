<?php
declare(strict_types=1);

namespace Tonyapps\AdvancedReports\Model;

use Magento\Framework\App\Area;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\MixedPart;

/**
 * Templated email with file attachments. Magento's MimeMessage keeps only the first text part, so the
 * files are added to the underlying Symfony message after the template has been rendered.
 * Rendered in the frontend area of the default store view: the shared email header/footer only resolve there.
 */
class AttachmentMailer
{
    public function __construct(
        private readonly TransportBuilder $transportBuilder,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @param string[] $to
     * @param array<string, mixed> $vars
     * @param array<string, string> $attachments file name => CSV contents
     */
    public function send(array $to, string $templateId, array $vars, array $attachments): void
    {
        $transport = $this->transportBuilder
            ->setTemplateIdentifier($templateId)
            ->setTemplateOptions([
                'area' => Area::AREA_FRONTEND,
                'store' => (int)$this->storeManager->getDefaultStoreView()->getId(),
            ])
            ->setTemplateVars($vars)
            ->setFromByScope('general')
            ->addTo($to)
            ->getTransport();

        $message = $transport->getMessage();
        // Only core's EmailMessage exposes the Symfony message; a third-party SMTP transport may not.
        if (!method_exists($message, 'getSymfonyMessage')) {
            throw new LocalizedException(__('The mail transport in use cannot send attachments.'));
        }
        $message = $message->getSymfonyMessage();
        $parts = [$message->getBody()];
        foreach ($attachments as $name => $contents) {
            $parts[] = new DataPart($contents, $name, 'text/csv');
        }
        $message->setBody(new MixedPart(...$parts));

        $transport->sendMessage();
    }
}
