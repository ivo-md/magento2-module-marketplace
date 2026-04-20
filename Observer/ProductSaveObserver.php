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

            // Use helper to prepare payload with description, brand, category
            $payload = $this->_ivoHelper->prepareProductPayload($product);
            if (!$payload) {
                return;
            }

            // Perform Sync
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
