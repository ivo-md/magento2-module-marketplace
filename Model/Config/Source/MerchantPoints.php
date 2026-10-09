<?php

namespace Ivo\Marketplace\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Ivo\Marketplace\Helper\Config;

class MerchantPoints implements OptionSourceInterface
{
    protected $_ivoHelper;

    public function __construct(Config $helper)
    {
        $this->_ivoHelper = $helper;
    }

    public function toOptionArray()
    {
        $options = [];
        try {
            $points = $this->_ivoHelper->getMerchantPointList();

            if (is_array($points)) {
                foreach ($points as $point) {
                    $label = $point['name'] ?? $point['id'];
                    $options[] = [
                        'value' => (string)$point['id'],
                        'label' => $label
                    ];
                }
            }
        } catch (\Exception $e) {
            $this->_ivoHelper->getLogger()->warning('IVO Marketplace: could not list Merchant Points: ' . $e->getMessage());
        }

        // The select offers only what IVO just listed. When that fetch fails,
        // or no longer lists the saved point, Save Config would store another
        // value (or '') and every push would stop or move; the saved point
        // stays selectable until the merchant picks another.
        $storedId = $this->_ivoHelper->getStoredMerchantPointId();
        if ($storedId !== '' && !in_array($storedId, array_column($options, 'value'), true)) {
            array_unshift($options, ['value' => $storedId, 'label' => __('%1 (currently saved)', $storedId)]);
        }

        if (empty($options)) {
            $options[] = ['value' => '', 'label' => __('No Merchant Points Found / API Key Missing')];
        }

        return $options;
    }
}
