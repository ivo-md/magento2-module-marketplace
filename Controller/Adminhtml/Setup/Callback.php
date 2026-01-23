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
                    $this->_ivoHelper->saveApiKey($apiKey);
                    
                    try {
                        // Pass the API key directly to avoid cache latency issues
                        $points = $this->_ivoHelper->fetchMerchantPoints($apiKey);
                        
                        // Check if response contains 'points' (API format) or 'data' (Generic format)
                        $pointsList = $points['points'] ?? $points['data'] ?? [];

                        if (is_array($pointsList) && count($pointsList) > 0) {
                            $firstPointId = $pointsList[0]['id'];
                            $pointName = $pointsList[0]['name'] ?? $firstPointId;
                            $this->_ivoHelper->setMerchantPointId($firstPointId);
                            $this->messageManager->addSuccessMessage(__('IVO Marketplace configured successfully. Merchant Point set to: %1', $pointName));
                        } else {
                            $debugMsg = isset($points['message']) ? $points['message'] : 'No data';
                            $this->messageManager->addWarningMessage(__('IVO Marketplace configured, but no Merchant Points found. Debug: %1', $debugMsg));
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
