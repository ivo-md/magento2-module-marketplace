<?php

namespace Ivo\Marketplace\Controller\Adminhtml\Sync;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Ivo\Marketplace\Helper\Config;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Store\Model\StoreManagerInterface;

class All extends Action implements HttpGetActionInterface
{
    protected $_ivoHelper;
    protected $_productCollectionFactory;
    protected $_stockRegistry;
    protected $_storeManager;

    public function __construct(
        Context $context,
        Config $helper,
        CollectionFactory $productCollectionFactory,
        StockRegistryInterface $stockRegistry,
        StoreManagerInterface $storeManager
    ) {
        $this->_ivoHelper = $helper;
        $this->_productCollectionFactory = $productCollectionFactory;
        $this->_stockRegistry = $stockRegistry;
        $this->_storeManager = $storeManager;
        parent::__construct($context);
    }

    public function execute()
    {
        try {
            $merchantPointId = $this->_ivoHelper->getMerchantPointId();
            if (!$merchantPointId) {
                throw new \Exception(__('Unable to fetch Merchant Point ID. Please check API Key configuration.'));
            }

            $collection = $this->_productCollectionFactory->create();
            $collection->addAttributeToSelect(['name', 'price', 'sku']);

            $productsPayload = [];
            $store = $this->_storeManager->getStore();
            $currencyCode = $store->getCurrentCurrencyCode();
            
            // Safety check
            if (!$currencyCode) {
                $currencyCode = $store->getBaseCurrencyCode();
            }

            foreach ($collection as $product) {
                $stockItem = $this->_stockRegistry->getStockItem($product->getId());
                $qty = $stockItem ? (int)$stockItem->getQty() : 0;

                $productsPayload[] = [
                    'name' => $product->getName(),
                    'price' => (float)$product->getPrice(),
                    'currency' => $currencyCode,
                    'availability' => $qty,
                    'merchant_point_id' => $merchantPointId,
                    'merchant_internal_id' => $product->getSku()
                ];

                if (count($productsPayload) >= 100) {
                    $this->_ivoHelper->syncProducts($productsPayload);
                    $productsPayload = [];
                }
            }

            if (!empty($productsPayload)) {
                $this->_ivoHelper->syncProducts($productsPayload);
            }

            $this->messageManager->addSuccessMessage(__('Products synchronization started successfully.'));

        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        /** @var \Magento\Framework\Controller\Result\Redirect $resultRedirect */
        $resultRedirect = $this->resultRedirectFactory->create();
        $resultRedirect->setPath('adminhtml/system_config/edit', ['section' => 'ivo_marketplace']);
        return $resultRedirect;
    }
}
