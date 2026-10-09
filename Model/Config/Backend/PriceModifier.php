<?php

namespace Ivo\Marketplace\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Ivo\Marketplace\Helper\Config;

class PriceModifier extends Value
{
    protected $_ivoHelper;

    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\App\Config\ScopeConfigInterface $config,
        \Magento\Framework\App\Cache\TypeListInterface $cacheTypeList,
        Config $helper,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->_ivoHelper = $helper;
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * A new modifier means new prices for the whole catalogue. They are not
     * sent from here: this runs inside the config-save transaction, before the
     * new value is readable (the push went out with the old modifier), and a
     * catalogue in one request outlives shop timeouts. The config page sees
     * the request and re-sends the catalogue slice by slice.
     */
    public function afterSave()
    {
        // '' and '0' are the same modifier
        if ((float)$this->getValue() !== (float)$this->getOldValue()) {
            $this->_ivoHelper->requestResync();
        }
        return parent::afterSave();
    }
}
