<?php

namespace Ivo\Marketplace\Ui\DataProvider\Product\Form\Modifier;

use Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\AbstractModifier;
use Magento\Catalog\Model\Locator\LocatorInterface;
use Ivo\Marketplace\Helper\Config;
use Magento\Ui\Component\Form\Fieldset;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\UrlInterface;

class IvoStatus extends AbstractModifier
{
    protected $locator;
    protected $helper;
    protected $localeResolver;
    protected $urlBuilder;

    public function __construct(
        LocatorInterface $locator, 
        Config $helper,
        ResolverInterface $localeResolver,
        UrlInterface $urlBuilder
    ) {
        $this->locator = $locator;
        $this->helper = $helper;
        $this->localeResolver = $localeResolver;
        $this->urlBuilder = $urlBuilder;
    }

    public function modifyData(array $data)
    {
        return $data;
    }

    public function modifyMeta(array $meta)
    {
        if (!$this->locator->getProduct()->getId()) {
            return $meta;
        }

        $content = $this->getIvoContent();
        
        $meta = array_replace_recursive(
            $meta,
            [
                'ivo_marketplace' => [
                    'arguments' => [
                        'data' => [
                            'config' => [
                                'label' => __('IVO Marketplace'),
                                'componentType' => Fieldset::NAME,
                                'collapsible' => true,
                                'sortOrder' => 50
                            ],
                        ],
                    ],
                    'children' => [
                         'ivo_status_html' => [
                            'arguments' => [
                                'data' => [
                                    'config' => [
                                        'formElement' => 'container',
                                        'componentType' => 'container',
                                        'component' => 'Magento_Ui/js/form/components/html',
                                        'content' => $content,
                                        'sortOrder' => 10,
                                    ],
                                ],
                            ],
                        ]
                    ]
                ]
            ]
        );
        return $meta;
    }
    
    private function getIvoContent()
    {
         $product = $this->locator->getProduct();
         
         if (!$this->helper->getApiKey()) {
             return "<b>" . __('IVO Marketplace') . ":</b> " . __('Not configured.');
         }

         $sku = $product->getSku();
         $ivoInfo = null;
         try {
             $ivoInfo = $this->helper->getProductIvoInfo($sku);
         } catch (\Exception $e) {
             return "<b>" . __('IVO Marketplace') . ":</b> " . __('Error fetching status.') . ": " . $e->getMessage();
         }
         
         $html = "<div class='ivo-status-container' style='padding: 15px; background: #f8f8f8; border: 1px solid #d1d1d1; border-radius: 5px;'>";

         if (isset($ivoInfo['status']) && $ivoInfo['status'] === 'success' && isset($ivoInfo['product'])) {
             $p = $ivoInfo['product'];
             $status = $p['status'] ?? 'unknown';
             $statusLabel = __(ucfirst($status));
             
             $color = '#333';
             if ($status === 'active') $color = '#28a745';
             if ($status === 'inactive') $color = '#dc3545';
             if ($status === 'draft') $color = '#ffc107';

             $html .= "<h3 style='margin-top:0'>" . __('IVO Status') . ": <span style='font-weight:bold; color: $color;'>$statusLabel</span></h3>";
             
             if ($status === 'active') {
                 $html .= "<p style='margin-bottom:15px;'>" . __('The product is visible and purchasable.') . "</p>";
                 
                 // Language logic
                 $currentLocale = $this->localeResolver->getLocale();
                 $langCode = substr($currentLocale, 0, 2); 
                 
                 $targetUrl = null;
                 $btnText = __('View Product');
                 
                 if (isset($p['urls'][$langCode])) {
                     $targetUrl = $p['urls'][$langCode];
                     $btnText .= " (" . strtoupper($langCode) . ")";
                 } elseif (isset($p['urls']['en'])) {
                     $targetUrl = $p['urls']['en'];
                     $btnText .= " (EN)";
                 } elseif (isset($p['url'])) {
                     $targetUrl = $p['url'];
                 }
                 
                 if ($targetUrl) {
                     $html .= "<a href='$targetUrl' target='_blank' style='
                        background-color: #eb5202;
                        color: white;
                        padding: 10px 20px;
                        text-decoration: none;
                        border-radius: 3px;
                        font-weight: bold;
                        display: inline-block;
                     '>$btnText</a>";
                 }
             } elseif ($status === 'draft') {
                  $html .= "<p>" . __('The product is still being created or reviewed.') . "</p>";
             } elseif ($status === 'inactive') {
                  $html .= "<p>" . __('The product is hidden or disabled.') . "</p>";
             }
         } else {
             // Handle error or other statuses
             $status = $ivoInfo['status'] ?? 'Error';
             $message = $ivoInfo['message'] ?? '';
             
             // Translate known messages
             if ($message == 'offer_not_found') {
                $message = __('Product not found on marketplace.');
             } elseif ($message == 'Product not found') {
                $message = __('Product not found on marketplace.');
             }
             
             $displayStatus = __(ucfirst($status));
             if ($message) {
                 $displayStatus .= " (" . $message . ")";
             }
             
             $color = ($status === 'error') ? '#dc3545' : '#ffc107';
             
             $html .= "<h3 style='margin-top:0'>" . __('IVO Status') . ": <span style='font-weight:bold; color: $color;'>$displayStatus</span></h3>";
             
             $statusDescriptions = [
                'skipped_no_variants' => __('No variants were found for this product.'),
                'skipped_no_match' => __('The product details are too generic to accurately identify the specific item being sold.'),
                'unauthorized_category' => __('Merchant not authorized for product category.'),
                'created' => __('Offer was successfully created for the product/variant.'),
                'error' => __('An error occurred during offer creation.'),
                'pending_creation' => __('Found existing product, offer creation is pending.')
             ];
             
             $offerStatus = $ivoInfo['offer_status'] ?? null;
             
             if ($offerStatus && isset($statusDescriptions[$offerStatus])) {
                 $html .= "<p>" . $statusDescriptions[$offerStatus] . "</p>";
             } else {
                 $html .= "<ul style='padding-left: 20px; margin-bottom: 0;'>";
                 if ($offerStatus) {
                     $html .= "<li><strong>" . __('Offer Status') . ":</strong> " . $offerStatus . "</li>";
                 }
                 if (isset($ivoInfo['offer_note'])) {
                     $html .= "<li><strong>" . __('Note') . ":</strong> " . $ivoInfo['offer_note'] . "</li>";
                 }
                 if (isset($ivoInfo['detail'])) {
                     $html .= "<li><strong>" . __('Detail') . ":</strong> " . $ivoInfo['detail'] . "</li>";
                 }
                 if (isset($ivoInfo['reason'])) {
                     $html .= "<li><strong>" . __('Reason') . ":</strong> " . $ivoInfo['reason'] . "</li>";
                 }
                 if (empty($ivoInfo)) {
                     $html .= "<li>" . __('Product not found on marketplace.') . "</li>";
                 }
                 $html .= "</ul>";
             }
         }
         
         $html .= "</div>";

         $syncUrl = $this->urlBuilder->getUrl('ivo_marketplace/product/sync');
         
         $forceSyncLabel = __('Force Sync with IVO');
         $syncingLabel = __('Syncing...');
         
         $html .= "
         <div style='margin-top: 15px; padding-top: 10px; border-top: 1px dashed #ccc;'>
            <button type='button' class='action-default' onclick='ivoSyncProduct(\"$sku\")'>
                <span>$forceSyncLabel</span>
            </button>
            <span id='ivo_sync_message' style='margin-left: 10px; font-weight: bold;'></span>
         </div>
         <script>
            function ivoSyncProduct(sku) {
                require(['jquery', 'mage/translate'], function($, $t) {
                    $('#ivo_sync_message').text('$syncingLabel').css('color', 'black');
                    $.ajax({
                        url: '$syncUrl',
                        data: {sku: sku, form_key: window.FORM_KEY},
                        type: 'POST',
                        dataType: 'json',
                        success: function(res) {
                            if(res.status === 'success') {
                                $('#ivo_sync_message').text(res.message).css('color', 'green');
                            } else {
                                $('#ivo_sync_message').text(res.message).css('color', 'red');
                            }
                        },
                        error: function() {
                             $('#ivo_sync_message').text('" . __('Connection error.') . "').css('color', 'red');
                        }
                    });
                });
            }
         </script>
         ";

         return $html;
    }
}
