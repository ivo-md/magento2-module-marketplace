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

         // API response format: { status: "success", product: {...}, offer: {...} }
         if (isset($ivoInfo['status']) && $ivoInfo['status'] === 'success' && isset($ivoInfo['product'])) {
             $p = $ivoInfo['product'];
             
             // Success - offer found, show product info
             $color = '#28a745';
             $html .= "<h3 style='margin-top:0'>" . __('IVO Status') . ": <span style='font-weight:bold; color: $color;'>" . __('Active') . "</span></h3>";
             $html .= "<p style='margin-bottom:15px;'>" . __('The product is visible and purchasable.') . "</p>";
             
             // Language logic for View Product button
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
         } else {
             // Error status - show appropriate message based on message code
             $message = $ivoInfo['message'] ?? 'unknown';
             
             // Message descriptions
             $messageDescriptions = [
                'not_imported' => __('Product has not been synced to IVO yet.'),
                'queued' => __('Product is queued for processing.'),
                'processing' => __('Product is currently being processed.'),
                'pending_approval' => __('Product created but still pending approval.'),
                'import_error' => __('An error occurred during import.'),
                'skipped_no_match' => __('The product details are too generic to accurately identify the specific item being sold.'),
                'unauthorized_category' => __('Merchant not authorized for product category.'),
                'offer_deleted' => __('The offer was deleted from the marketplace.'),
                'offer_not_found' => __('Product not found on marketplace.')
             ];
             
             // Determine color based on message type
             $color = '#dc3545'; // Red for errors
             $statusLabel = __('Error');
             
             if (in_array($message, ['queued', 'processing', 'pending_approval'])) {
                 $color = '#ffc107'; // Yellow for pending
                 $statusLabel = __('Pending');
             } elseif ($message === 'not_imported') {
                 $color = '#6c757d'; // Gray for not synced
                 $statusLabel = __('Not Synced');
             }
             
             $html .= "<h3 style='margin-top:0'>" . __('IVO Status') . ": <span style='font-weight:bold; color: $color;'>$statusLabel</span></h3>";
             
             if (isset($messageDescriptions[$message])) {
                 $html .= "<p>" . $messageDescriptions[$message] . "</p>";
             } else {
                 $html .= "<p>" . __('Unknown status') . ": " . $message . "</p>";
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
                require(['jquery', 'mage/translate'], function($, \$t) {
                    $('#ivo_sync_message').text('$syncingLabel').css('color', 'black');
                    $.ajax({
                        url: '$syncUrl',
                        data: {sku: sku, form_key: window.FORM_KEY},
                        type: 'POST',
                        dataType: 'json',
                        success: function(res) {
                            if(res.status === 'success') {
                                $('#ivo_sync_message').text(res.message).css('color', 'green');
                                $('#ivo_sync_message').append(' <span style=\"color:#666;\">Refreshing status...</span>');
                                setTimeout(function() { location.reload(); }, 5000);
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
