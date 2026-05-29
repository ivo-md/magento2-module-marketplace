<?php

namespace Ivo\Marketplace\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Ivo\Marketplace\Helper\Config;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;

class PriceModifier extends Value
{
    protected $_ivoHelper;
    protected $_productCollectionFactory;
    protected $_productRepository;

    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\App\Config\ScopeConfigInterface $config,
        \Magento\Framework\App\Cache\TypeListInterface $cacheTypeList,
        Config $helper,
        CollectionFactory $productCollectionFactory,
        ProductRepositoryInterface $productRepository,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->_ivoHelper = $helper;
        $this->_productCollectionFactory = $productCollectionFactory;
        $this->_productRepository = $productRepository;
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    public function afterSave()
    {
        if ($this->isValueChanged()) {
            try {
                $merchantPointId = $this->_ivoHelper->getMerchantPointId();
                if ($merchantPointId) {
                    $collection = $this->_productCollectionFactory->create();
                    $collection->addAttributeToSelect('*');

                    $productsPayload = [];

                    foreach ($collection as $product) {
                        try {
                            $fullProduct = $this->_productRepository->getById($product->getId());
                        } catch (\Exception $e) {
                            $fullProduct = $product;
                        }
                        
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
                }
            } catch (\Exception $e) {
                // Silently handle error
            }
        }
        return parent::afterSave();
    }
}
