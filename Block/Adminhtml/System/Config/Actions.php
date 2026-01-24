<?php

namespace Ivo\Marketplace\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Backend\Block\Widget\Button;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Ivo\Marketplace\Helper\Config;

class Actions extends Field
{
    protected $_ivoHelper;

    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        Config $helper,
        array $data = []
    ) {
        $this->_ivoHelper = $helper;
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        $isConfigured = $this->_ivoHelper->isConfigured();
        $apiKey = $this->_ivoHelper->getApiKey();
        
        if (!$apiKey) {
            $isConfigured = false;
        }

        $html = '';

        if (!$isConfigured) {
            // NOT CONFIGURED: Show Configure button
            $html .= '<div class="message message-notice" style="margin-bottom: 15px;">';
            $html .= '<span>' . __('IVO Marketplace is installed but not configured. Please configure it to start selling on IVO.') . '</span>';
            $html .= '</div>';
            
            $url = $this->getUrl('ivo_marketplace/setup/index');
            /** @var Button $button */
            $button = $this->getLayout()->createBlock(Button::class)
                ->setData([
                    'id' => 'ivo_marketplace_configure',
                    'label' => __('Configure Plugin'),
                    'onclick' => "setLocation('$url')",
                    'class' => 'action-default primary scale-up' 
                ]);
            $html .= $button->toHtml();
        } else {
            // CONFIGURED: Check if API is valid
            $merchantInfo = $this->_ivoHelper->checkApiKey();
            
            if (isset($merchantInfo['error'])) {
                // API ERROR: Show error and Reconfigure button
                $html .= '<div class="message message-error" style="margin-bottom: 15px;">';
                $html .= '<span>' . __('IVO API Error:') . ' ' . htmlspecialchars($merchantInfo['error']) . '</span>';
                $html .= '</div>';
                
                $urlReconfig = $this->getUrl('ivo_marketplace/setup/index');
                $buttonReconfig = $this->getLayout()->createBlock(Button::class)
                    ->setData([
                        'id' => 'ivo_marketplace_reconfigure',
                        'label' => __('Reconfigure'),
                        'onclick' => "setLocation('$urlReconfig')",
                        'class' => 'action-default primary'
                    ]);
                $html .= $buttonReconfig->toHtml();
            } else {
                // API VALID: Check merchant point
                $merchantPointId = $this->_ivoHelper->getMerchantPointId();
                
                if (!$merchantPointId) {
                    // No merchant point - show warning
                    $html .= '<div class="message message-warning" style="margin-bottom: 15px;">';
                    $html .= '<span>' . __('Please select a Merchant Point. The plugin will not sync products until a merchant point is selected.') . '</span>';
                    $html .= '</div>';
                }
                
                // Reconfigure Button (Small)
                $urlReconfig = $this->getUrl('ivo_marketplace/setup/index');
                $buttonReconfig = $this->getLayout()->createBlock(Button::class)
                    ->setData([
                        'id' => 'ivo_marketplace_reconfigure',
                        'label' => __('Reconfigure'),
                        'onclick' => "setLocation('$urlReconfig')",
                        'class' => 'action-default',
                        'style' => 'margin-right: 15px;'
                    ]);
                
                // Force Sync Button (Big/Primary)
                $urlSync = $this->getUrl('ivo_marketplace/sync/all');
                $buttonSync = $this->getLayout()->createBlock(Button::class)
                    ->setData([
                        'id' => 'ivo_marketplace_force_sync',
                        'label' => __('Force Sync'),
                        'onclick' => "setLocation('$urlSync')",
                        'class' => 'action-default primary'
                    ]);

                $html .= '<div style="margin-top:10px;">';
                $html .= $buttonReconfig->toHtml();
                $html .= $buttonSync->toHtml();
                $html .= '</div>';
            }
        }

        return $html;
    }
}
