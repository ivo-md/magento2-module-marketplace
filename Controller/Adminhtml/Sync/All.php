<?php

namespace Ivo\Marketplace\Controller\Adminhtml\Sync;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Ivo\Marketplace\Helper\Config;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;

class All extends Action implements HttpGetActionInterface
{
    protected $_ivoHelper;
    protected $_productCollectionFactory;
    protected $_productRepository;

    public function __construct(
        Context $context,
        Config $helper,
        CollectionFactory $productCollectionFactory,
        ProductRepositoryInterface $productRepository
    ) {
        $this->_ivoHelper = $helper;
        $this->_productCollectionFactory = $productCollectionFactory;
        $this->_productRepository = $productRepository;
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
            $collection->addAttributeToSelect('*'); // Select all attributes for description

            $productsPayload = [];

            foreach ($collection as $product) {
                // Load full product to get all attributes
                try {
                    $fullProduct = $this->_productRepository->getById($product->getId());
                } catch (\Exception $e) {
                    $fullProduct = $product;
                }
                
                // Use helper to prepare payload with description, brand, category
                $payload = $this->_ivoHelper->prepareProductPayload($fullProduct);
                if ($payload) {
                    $productsPayload[] = $payload;
                }

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
