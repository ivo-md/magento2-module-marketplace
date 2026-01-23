<?php

namespace Ivo\Marketplace\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Ivo\Marketplace\Helper\Config;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Store\Model\StoreManagerInterface;

class ProductSaveObserver implements ObserverInterface
{
    protected $_ivoHelper;
    protected $_stockRegistry;
    protected $_storeManager;

    public function __construct(
        Config $helper,
        StockRegistryInterface $stockRegistry,
        StoreManagerInterface $storeManager
    ) {
        $this->_ivoHelper = $helper;
        $this->_stockRegistry = $stockRegistry;
        $this->_storeManager = $storeManager;
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
            if (!$merchantPointId) {
                return;
            }
            
            // Get stock quantity
            $stockItem = $this->_stockRegistry->getStockItem($product->getId());
            $qty = $stockItem ? (int)$stockItem->getQty() : 0;
            
            $store = $this->_storeManager->getStore();
            $currencyCode = $store->getCurrentCurrencyCode();
            if (!$currencyCode) {
                 $currencyCode = $store->getBaseCurrencyCode();
            }

            $payload = [
                'name' => $product->getName(),
                'price' => (float)$product->getPrice(),
                'currency' => $currencyCode,
                'availability' => $qty,
                'merchant_point_id' => $merchantPointId,
                'merchant_internal_id' => $product->getSku()
            ];

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
