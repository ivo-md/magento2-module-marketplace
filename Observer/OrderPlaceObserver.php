<?php

namespace Ivo\Marketplace\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Ivo\Marketplace\Helper\Config;
use Magento\Catalog\Api\ProductRepositoryInterface;

/**
 * A sale in the shop lowers stock, so IVO gets the new availability of what
 * was sold. Only availability travels (availability_only): the offers exist
 * already, and a full payload of a product IVO would reject (no price, a
 * container type) took the whole call down with it.
 */
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

            // A store with an API key and a merchant point pushes. "Update stock
            // on sale" is about the other direction (IVO sales lowering shop
            // stock, see the stock webhook) and does not gate this push.
            if (!$this->_ivoHelper->getApiKey()) {
                return;
            }
            $merchantPointId = $this->_ivoHelper->getMerchantPointId();
            if (!$merchantPointId) {
                return;
            }

            $processedIds = [];
            $productsPayload = [];

            foreach ($order->getAllItems() as $item) {
                // A configurable or bundle line holds the simple products
                // actually sold as its children; those carry the stock
                if ($item->getHasChildren() || $item->getChildrenItems()) {
                    continue;
                }

                // By id: the order line's SKU carries custom-option suffixes
                // and matches no product
                $productId = (int)$item->getProductId();
                if (!$productId || isset($processedIds[$productId])) {
                    continue;
                }
                $processedIds[$productId] = true;

                try {
                    $product = $this->_productRepository->getById($productId);
                } catch (\Exception $e) {
                    $this->_ivoHelper->getLogger()->warning(
                        'IVO Order Place Sync: product ' . $productId . ' (' . $item->getSku() . ') of order '
                        . $order->getIncrementId() . ' could not be loaded: ' . $e->getMessage()
                    );
                    continue;
                }

                $productsPayload[] = [
                    'availability_only' => true,
                    'merchant_internal_id' => $product->getSku(),
                    'merchant_point_id' => $merchantPointId,
                    'availability' => $this->_ivoHelper->getAvailability($product),
                ];
            }

            // IVO takes at most SYNC_BATCH_LIMIT per call; a rejected push is logged by the helper
            foreach (array_chunk($productsPayload, Config::SYNC_BATCH_LIMIT) as $batch) {
                $this->_ivoHelper->syncProducts($batch);
            }

        } catch (\Exception $e) {
            $logger = $this->_ivoHelper->getLogger();
            if ($logger) {
                $logger->error('IVO Order Place Sync Error: ' . $e->getMessage());
            }
        }
    }
}
