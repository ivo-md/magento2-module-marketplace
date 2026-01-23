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
            $payload = $this->helper->prepareProductPayload($product);
            
            if (!$payload) {
                return $result->setData(['status' => 'error', 'message' => 'Could not prepare payload or Merchant Point ID missing']);
            }

            // Sync
            $syncResult = $this->helper->syncProducts([$payload]);
            
            // Check success logic (assuming 2xx is success)
            if ($syncResult['code'] >= 200 && $syncResult['code'] < 300) {
                 return $result->setData([
                     'status' => 'success', 
                     'message' => 'Product synced successfully!',
                     'api_code' => $syncResult['code']
                 ]);
            } else {
                 return $result->setData([
                     'status' => 'error', 
                     'message' => 'Sync failed. API Code: ' . $syncResult['code'],
                     'details' => $syncResult['response']
                 ]);
            }

        } catch (\Exception $e) {
            return $result->setData(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
}
