<?php

namespace Ivo\Marketplace\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Ivo\Marketplace\Helper\Config;

/**
 * A deleted product disappears from every future sync, so nothing would ever
 * zero its IVO offer. Push the zero on delete_before, while the product's SKU
 * can still be read. The push is availability-only — the server updates rows
 * and offers it already knows and never creates anything from it.
 */
class ProductDeleteObserver implements ObserverInterface
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

            $merchantPointId = $this->_ivoHelper->getMerchantPointId();
            $sku = $product ? (string)$product->getSku() : '';
            if (!$merchantPointId || $sku === '') {
                return;
            }

            $this->_ivoHelper->syncProducts([[
                'availability_only' => true,
                'availability' => 0,
                'merchant_internal_id' => $sku,
                'merchant_point_id' => $merchantPointId,
            ]]);

        } catch (\Exception $e) {
            // Log error silently
            $logger = $this->_ivoHelper->getLogger();
            if ($logger) {
                $logger->error('IVO Product Delete Sync Error: ' . $e->getMessage());
            }
        }
    }
}
