<?php
declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Plugin;

use Etechflow\OptionsPlugin\Model\Config;
use Magento\Catalog\Model\Product\Option\Type\File;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * When a file-type custom option throws "required option(s) weren't entered" in
 * PROCESS_MODE_FULL (Magento's hard-coded behaviour, even when is_require=0),
 * suppress it. Customer is then free to add the product to cart without uploading
 * a file. Behaviour is gated by the admin config flag etechflow_options/file_options/soft_required.
 */
class SoftRequiredFile
{
    private Config $config;
    private LoggerInterface $logger;

    public function __construct(Config $config, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    public function aroundValidateUserValue(File $subject, callable $proceed, $values)
    {
        try {
            return $proceed($values);
        } catch (LocalizedException $e) {
            if (!$this->config->isFileSoftRequired()) {
                throw $e;
            }
            $option = $subject->getOption();
            if ($option && !$option->getIsRequire()) {
                if ($this->config->shouldLogRelaxed()) {
                    $this->logger->info(sprintf(
                        '[Etechflow_OptionsPlugin] Relaxed file option: id=%s title="%s" msg="%s"',
                        $option->getOptionId(),
                        (string)$option->getTitle(),
                        $e->getMessage()
                    ));
                }
                $subject->setUserValue(null);
                $subject->setIsValid(true);
                return $subject;
            }
            throw $e;
        }
    }
}
