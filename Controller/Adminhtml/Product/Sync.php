<?php

namespace Ivo\Marketplace\Controller\Adminhtml\Product;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Ivo\Marketplace\Helper\Config;

class Sync extends Action
{
    protected $resultJsonFactory;
    protected $productRepository;
    protected $helper;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        ProductRepositoryInterface $productRepository,
        Config $helper
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->productRepository = $productRepository;
        $this->helper = $helper;
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $sku = $this->getRequest()->getParam('sku');

        if (!$sku) {
             return $result->setData(['status' => 'error', 'message' => 'SKU is required']);
        }

        try {
            $product = $this->productRepository->get($sku);

            if (!$this->helper->isSentAsOffer($product)) {
                return $result->setData(['status' => 'error', 'message' => (string)__('Configurable and grouped products are not sent to IVO themselves: their simple products are. Sync those instead.')]);
            }

            $payload = $this->helper->prepareProductPayload($product);
            
            if (!$payload) {
                return $result->setData(['status' => 'error', 'message' => 'Could not prepare payload or Merchant Point ID missing']);
            }

            // What IVO checks is the price sent (special price, modifier), not the catalog price
            if (!$this->helper->isValidSyncPayload($payload)) {
                return $result->setData(['status' => 'error', 'message' => 'Product cannot be synced. Please ensure it has a valid price.']);
            }

            // Sync
            $syncResult = $this->helper->syncProducts([$payload]);
            
            if ($this->helper->isSyncSuccess($syncResult)) {
                 return $result->setData([
                     'status' => 'success', 
                     'message' => 'Product synced successfully!',
                     'api_code' => $syncResult['code']
                 ]);
            } else {
                 return $result->setData([
                     'status' => 'error', 
                     'message' => $this->helper->getSyncErrorMessage($syncResult, [$payload]),
                     'details' => is_array($syncResult) ? $syncResult['response'] : null
                 ]);
            }

        } catch (\Exception $e) {
            return $result->setData(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
}
