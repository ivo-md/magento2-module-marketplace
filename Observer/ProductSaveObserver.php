<?php

namespace Ivo\Marketplace\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Ivo\Marketplace\Helper\Config;

class ProductSaveObserver implements ObserverInterface
{
    protected $_ivoHelper;

    public function __construct(
        Config $helper
    ) {
        $this->_ivoHelper = $helper;
    }

    public function execute(Observer $observer)
    {
        try {
            /** @var \Magento\Catalog\Model\Product $product */
            $product = $observer->getEvent()->getProduct();
            
            // Check if API key is configured
            if (!$this->_ivoHelper->getApiKey()) {
                return;
            }

            // A configurable, grouped or bundle product is not an offer; its simple products are
            if (!$this->_ivoHelper->isSentAsOffer($product)) {
                return;
            }

            // Use helper to prepare payload with description, brand, category
            // (null without a merchant point; the helper logs that)
            $payload = $this->_ivoHelper->prepareProductPayload($product);
            if (!$payload) {
                return;
            }

            if (!$this->_ivoHelper->isValidSyncPayload($payload)) {
                $this->_ivoHelper->getLogger()->warning(
                    'IVO Product Save Sync: skipped product ' . $product->getSku() . ' (id ' . (int)$product->getId() . '):'
                    . ' a non-empty name and a price of at least 0.01 are required.'
                );
                return;
            }

            // Perform Sync (a rejected push is logged by the helper)
            $this->_ivoHelper->syncProducts([$payload]);

        } catch (\Exception $e) {
            // Log error silently
            $logger = $this->_ivoHelper->getLogger();
            if ($logger) {
                $logger->error('IVO Product Save Sync Error: ' . $e->getMessage());
            }
        }
    }
}
