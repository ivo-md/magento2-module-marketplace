<?php

namespace Ivo\Marketplace\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Backend\Block\Template\Context;

class ApiKey extends Field
{
    protected $_encryptor;

    public function __construct(
        Context $context,
        EncryptorInterface $encryptor,
        array $data = []
    ) {
        $this->_encryptor = $encryptor;
        parent::__construct($context, $data);
    }

    /**
     * Retrieve element HTML
     *
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        $value = $element->getValue();

        if (empty($value)) {
            return '<strong style="color: #e22626;">Not Configured</strong>';
        }

        // Decrypt if it looks encrypted (standard Magento encryption prefix)
        // Check for 0:2: or 0:3: prefixes commonly used by Magento
        if (preg_match('/^0:[0-3]:/', $value)) {
            try {
                $value = $this->_encryptor->decrypt($value);
            } catch (\Exception $e) {
                // If decryption fails, keep original value (might be corrupted or wrong key)
            }
        }

        // If the value is somehow already masked by Magento (******), we can't show first 5 chars.
        // But since we control the display, we hope to get the real value.
        // If it is '******', we just return it.
        
        if (strpos($value, '*') === false && strlen($value) > 5) {
            $value = substr($value, 0, 5) . str_repeat('*', 15);
        } elseif (strlen($value) <= 5) {
             // If very short, showing all is technically "first 5".
        }

        return '<div style="padding-top: 7px; font-family: monospace; font-weight: bold;">' . htmlspecialchars($value) . '</div>';
    }
}
