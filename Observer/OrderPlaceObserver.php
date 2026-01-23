<?php

namespace Ivo\Marketplace\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Ivo\Marketplace\Helper\Config;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;

class OrderPlaceObserver implements ObserverInterface
{
    protected $_ivoHelper;
    protected $_stockRegistry;
    protected $_storeManager;
    protected $_productRepository;

    public function __construct(
        Config $helper,
        StockRegistryInterface $stockRegistry,
        StoreManagerInterface $storeManager,
        ProductRepositoryInterface $productRepository
    ) {
        $this->_ivoHelper = $helper;
        $this->_stockRegistry = $stockRegistry;
        $this->_storeManager = $storeManager;
        $this->_productRepository = $productRepository;
    }

    public function execute(Observer $observer)
    {
        try {
            /** @var \Magento\Sales\Model\Order $order */
            $order = $observer->getEvent()->getOrder();
            
            if (!$this->_ivoHelper->getApiKey()) {
                return;
            }

            $merchantPointId = $this->_ivoHelper->getMerchantPointId();
            if (!$merchantPointId) {
                return;
            }

            $store = $this->_storeManager->getStore($order->getStoreId());
            $currencyCode = $store->getCurrentCurrencyCode();
            if (!$currencyCode) {
                $currencyCode = $store->getBaseCurrencyCode();
            }

            $processedSkus = [];
            $productsPayload = [];

            foreach ($order->getAllItems() as $item) {
                $sku = $item->getSku();
                // Avoid processing same SKU twice
                if (in_array($sku, $processedSkus)) {
                    continue;
                }
                $processedSkus[] = $sku;

                try {
                    // Load product by SKU to ensure we have the correct object for Stock Registry
                    $product = $this->_productRepository->get($sku);
                    
                    $stockItem = $this->_stockRegistry->getStockItem($product->getId());
                    $qty = $stockItem ? (int)$stockItem->getQty() : 0;
                    
                    $productsPayload[] = [
                        'name' => $product->getName(),
                        'price' => (float)$product->getPrice(),
                        'currency' => $currencyCode,
                        'availability' => $qty,
                        'merchant_point_id' => $merchantPointId,
                        'merchant_internal_id' => $sku
                    ];
                } catch (\Exception $e) {
                    continue;
                }
            }

            if (!empty($productsPayload)) {
                $this->_ivoHelper->syncProducts($productsPayload);
            }

        } catch (\Exception $e) {
            $logger = $this->_ivoHelper->getLogger();
            if ($logger) {
                $logger->error('IVO Order Place Sync Error: ' . $e->getMessage());
            }
        }
    }
}
