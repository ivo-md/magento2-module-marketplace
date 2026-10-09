<?php

namespace Ivo\Marketplace\Controller\Adminhtml\Setup;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Ivo\Marketplace\Helper\Config;

class Callback extends Action implements HttpGetActionInterface
{
    /**
     * @var array
     */
    protected $_publicActions = ['callback'];

    protected $_ivoHelper;

    public function __construct(Context $context, Config $helper)
    {
        $this->_ivoHelper = $helper;
        parent::__construct($context);
    }

    public function execute()
    {
        // Prioritize GET parameter 'key' because Magento Admin URLs often include a route parameter '/key/...' (form key protection) which conflicts with our parameter name.
        $key = $this->getRequest()->getQuery('key');
        if (!$key) {
            $key = $this->getRequest()->getParam('key');
        }

        // Log the full request parameters for debugging
        $this->_ivoHelper->getLogger()->info('IVO Marketplace: Raw Callback Key: ' . $key);
        $this->_ivoHelper->getLogger()->info('IVO Marketplace: Full Params: ' . json_encode($this->getRequest()->getParams()));

        if ($key) {
            try {
                $apiKey = $this->_ivoHelper->decryptIvoKey($key);
                if ($apiKey) {
                    // A reconnect must not move pushes to another point than the one chosen
                    $storedPointId = $this->_ivoHelper->getStoredMerchantPointId();
                    $this->_ivoHelper->saveApiKey($apiKey);
                    
                    try {
                        // Pass the API key directly to avoid cache latency issues
                        $pointsList = $this->_ivoHelper->getMerchantPointList($apiKey);
                        $pointNames = [];
                        foreach ((array)$pointsList as $point) {
                            $pointNames[(string)$point['id']] = $point['name'] ?? $point['id'];
                        }

                        if ($pointsList === null) {
                            if ($storedPointId !== '') {
                                $this->messageManager->addWarningMessage(__('IVO Marketplace configured, but the Merchant Points could not be loaded. The saved Merchant Point %1 was kept.', $storedPointId));
                            } else {
                                $this->messageManager->addWarningMessage(__('IVO Marketplace configured, but the Merchant Points could not be loaded. Reload this page to choose the Merchant Point.'));
                            }
                        } elseif ($storedPointId !== '' && isset($pointNames[$storedPointId])) {
                            $this->messageManager->addSuccessMessage(__('IVO Marketplace configured successfully. Merchant Point kept: %1', $pointNames[$storedPointId]));
                        } elseif ($storedPointId !== '') {
                            // Another account, or the point is gone: the merchant decides where stock goes
                            $this->messageManager->addWarningMessage(__('IVO Marketplace configured, but the saved Merchant Point %1 is not one of this account\'s points. Choose the Merchant Point below and click Save Config.', $storedPointId));
                        } elseif (count($pointsList) === 1) {
                            $pointId = (string)$pointsList[0]['id'];
                            $this->_ivoHelper->setMerchantPointId($pointId);
                            $this->messageManager->addSuccessMessage(__('IVO Marketplace configured successfully. Merchant Point set to: %1', $pointNames[$pointId]));
                        } elseif (count($pointsList) > 1) {
                            $this->messageManager->addWarningMessage(__('IVO Marketplace configured. Choose the Merchant Point to sync products to below and click Save Config.'));
                        } else {
                            $this->messageManager->addWarningMessage(__('IVO Marketplace configured, but no Merchant Points found. Debug: %1', 'No data'));
                        }
                    } catch (\Exception $e) {
                         $this->messageManager->addWarningMessage(__('IVO Marketplace configured, but failed to auto-select Merchant Point: %1', $e->getMessage()));
                         $this->_ivoHelper->getLogger()->error('IVO Marketplace: Fetch points failed: ' . $e->getMessage());
                    }

                } else {
                    $this->messageManager->addErrorMessage(__('Invalid API Key received.'));
                }
            } catch (\Exception $e) {
                $this->messageManager->addErrorMessage($e->getMessage());
            }
        } else {
            $this->messageManager->addErrorMessage(__('No API Key received.'));
        }

        /** @var \Magento\Framework\Controller\Result\Redirect $resultRedirect */
        $resultRedirect = $this->resultRedirectFactory->create();
        $resultRedirect->setPath('adminhtml/system_config/edit', ['section' => 'ivo_marketplace']);
        return $resultRedirect;
    }
}
