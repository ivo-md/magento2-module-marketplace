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
            $pointsResponse = $this->_ivoHelper->fetchMerchantPoints();
            
            // Normalize: API returns 'points', generic wrapper might return 'data'
            $points = $pointsResponse['points'] ?? $pointsResponse['data'] ?? [];
            
            if (is_array($points) && count($points) > 0) {
                foreach ($points as $point) {
                    $label = $point['name'] ?? $point['id'];
                    $options[] = [
                        'value' => $point['id'],
                        'label' => $label
                    ];
                }
            }
        } catch (\Exception $e) {
            // Log error or ignore
        }
        
        if (empty($options)) {
            $options[] = ['value' => '', 'label' => __('No Merchant Points Found / API Key Missing')];
        }

        return $options;
    }
}
