<?php

namespace Ivo\Marketplace\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Ivo\Marketplace\Helper\Config;
use Magento\Catalog\Api\ProductRepositoryInterface;

class OrderPlaceObserver implements ObserverInterface
{
    protected $_ivoHelper;
    protected $_productRepository;

    public function __construct(
        Config $helper,
        ProductRepositoryInterface $productRepository
    ) {
        $this->_ivoHelper = $helper;
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
                    // Load product by SKU
                    $product = $this->_productRepository->get($sku);
                    
                    // Use helper to prepare payload with description, brand, category
                    $payload = $this->_ivoHelper->prepareProductPayload($product);
                    if ($payload) {
                        $productsPayload[] = $payload;
                    }
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
