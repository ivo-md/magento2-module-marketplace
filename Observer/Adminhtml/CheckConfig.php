<?php

namespace Ivo\Marketplace\Observer\Adminhtml;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Ivo\Marketplace\Helper\Config;
use Magento\Framework\Message\ManagerInterface;
use Magento\Backend\Model\UrlInterface;

class CheckConfig implements ObserverInterface
{
    protected $_ivoHelper;
    protected $messageManager;
    protected $urlBuilder;
    protected $session;

    public function __construct(
        Config $helper,
        ManagerInterface $messageManager,
        UrlInterface $urlBuilder,
        \Magento\Backend\Model\Auth\Session $session
    ) {
        $this->_ivoHelper = $helper;
        $this->messageManager = $messageManager;
        $this->urlBuilder = $urlBuilder;
        $this->session = $session;
    }

    public function execute(Observer $observer)
    {
        // Don't check if not logged in
        if (!$this->session->isLoggedIn()) {
            return;
        }

        if ($this->_ivoHelper->isConfigured()) {
            return;
        }

        $request = $observer->getEvent()->getRequest();
        $fullActionName = $request->getFullActionName();

        if ($request->isAjax()) {
            return;
        }

        if ($fullActionName == 'adminhtml_system_config_edit' && $request->getParam('section') == 'ivo_marketplace') {
            return;
        }
        
        if (strpos($fullActionName, 'ivo_marketplace_setup') !== false) {
            return;
        }

        $url = $this->urlBuilder->getUrl('ivo_marketplace/setup/index');
        $this->messageManager->addComplexNoticeMessage(
            'ivo_marketplace_not_configured',
            ['url' => $url]
        );
    }
}
