<?php

namespace Ivo\Marketplace\Controller\Adminhtml\Setup;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Ivo\Marketplace\Helper\Config;

class Index extends Action implements HttpGetActionInterface
{
    /**
     * @var array
     */
    protected $_publicActions = ['index'];

    protected $_ivoHelper;

    public function __construct(Context $context, Config $helper)
    {
        $this->_ivoHelper = $helper;
        parent::__construct($context);
    }

    public function execute()
    {
        $returnUrl = $this->getUrl('ivo_marketplace/setup/callback', ['_nosid' => true]);
        $url = $this->_ivoHelper->getSetupUrl($returnUrl);
        /** @var \Magento\Framework\Controller\Result\Redirect $resultRedirect */
        $resultRedirect = $this->resultRedirectFactory->create();
        $resultRedirect->setUrl($url);
        return $resultRedirect;
    }
}
