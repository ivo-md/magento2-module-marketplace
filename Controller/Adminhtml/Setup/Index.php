<?php

namespace Ivo\Marketplace\Controller\Adminhtml\Setup;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Ivo\Marketplace\Helper\Config;

class Index extends Action implements HttpGetActionInterface
{
    /**
     * Authorization level
     */
    const ADMIN_RESOURCE = 'Magento_Backend::admin';

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

    /**
     * Check if admin is allowed to access this action
     *
     * @return bool
     */
    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed(self::ADMIN_RESOURCE);
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
