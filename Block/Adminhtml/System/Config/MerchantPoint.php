<?php

namespace Ivo\Marketplace\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Backend\Block\Template\Context;
use Ivo\Marketplace\Helper\Config;

/**
 * The Merchant Point select, with the reason under it when IVO could not
 * list the points (the connection, the key, the integration, no point).
 * The options come from the source model, which asked IVO before this renders.
 */
class MerchantPoint extends Field
{
    protected $_ivoHelper;

    public function __construct(
        Context $context,
        Config $helper,
        array $data = []
    ) {
        $this->_ivoHelper = $helper;
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        $html = parent::_getElementHtml($element);

        if ($this->_ivoHelper->getApiKey() && $this->_ivoHelper->merchantPointsFailed()) {
            $html .= '<p class="note ivo-point-error" style="margin-top: 8px; color: #e22626; font-weight: 600;">⚠ '
                . $this->escapeHtml($this->_ivoHelper->getMerchantPointError())
                . '</p>';
        }

        return $html;
    }
}
