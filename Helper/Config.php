<?php

namespace Ivo\Marketplace\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;

class Config extends AbstractHelper
{
    const CONFIG_PATH_API_KEY = 'ivo_marketplace/general/api_key';
    const CONFIG_PATH_CONFIGURED = 'ivo_marketplace/general/configured';
    const CONFIG_PATH_MERCHANT_POINT_ID = 'ivo_marketplace/general/merchant_point_id';
    const CONFIG_PATH_PRICE_MODIFIER = 'ivo_marketplace/general/price_modifier';
    
    // IVO Endpoints - Hardcoded, not user-configurable
    const URL_SETUP = 'https://www.ivo.md/merchant/plugin/setup';
    const API_BASE_URL = 'https://a.ivo.md';

    public function getApiBaseUrl()
    {
        return getenv('IVO_API_URL') ?: self::API_BASE_URL;
    }

    protected $_storeManager;
    protected $_configWriter;
    protected $_cacheTypeList;
    protected $_productMetadata;
    protected $_encryptor;
    protected $_stockRegistry;

    public function __construct(
        Context $context,
        StoreManagerInterface $storeManager,
        WriterInterface $configWriter,
        TypeListInterface $cacheTypeList,
        ProductMetadataInterface $productMetadata,
        EncryptorInterface $encryptor,
        StockRegistryInterface $stockRegistry
    ) {
        $this->_storeManager = $storeManager;
        $this->_configWriter = $configWriter;
        $this->_cacheTypeList = $cacheTypeList;
        $this->_productMetadata = $productMetadata;
        $this->_encryptor = $encryptor;
        $this->_stockRegistry = $stockRegistry;
        parent::__construct($context);
    }

    public function isConfigured()
    {
        return $this->scopeConfig->getValue(self::CONFIG_PATH_CONFIGURED);
    }

    public function getPriceModifier()
    {
        return (float) $this->scopeConfig->getValue(self::CONFIG_PATH_PRICE_MODIFIER);
    }

    public function applyPriceModifier($price)
    {
        $modifier = $this->getPriceModifier();
        if ($modifier === 0.0) {
            return $price;
        }
        $adjustedPrice = $price * (1 + ($modifier / 100));
        return round(max(0.0, $adjustedPrice), 2);
    }

    public function getApiKey()
    {
        $value = $this->scopeConfig->getValue(self::CONFIG_PATH_API_KEY);
        try {
            return $this->_encryptor->decrypt($value);
        } catch (\Exception $e) {
            return $value;
        }
    }

    public function saveApiKey($apiKey)
    {
        $encrypted = $this->_encryptor->encrypt($apiKey);
        $this->_configWriter->save(self::CONFIG_PATH_API_KEY, $encrypted);
        $this->_configWriter->save(self::CONFIG_PATH_CONFIGURED, 1);
        $this->_cacheTypeList->cleanType('config');
    }

    public function setMerchantPointId($id)
    {
        $this->_configWriter->save(self::CONFIG_PATH_MERCHANT_POINT_ID, $id);
        $this->_cacheTypeList->cleanType('config');
    }

    public function getSetupUrl($returnUrl)
    {
        $baseUrl = $this->_storeManager->getStore()->getBaseUrl();

        $params = [
            'url_shop' => $baseUrl,
            'url_return' => $returnUrl,
            'platform' => 'magento',
            'version' => '1.0.8',
            'ip_server' => $_SERVER['SERVER_ADDR'] ?? '127.0.0.1',
            'os_server' => php_uname('s')
        ];

        $setupUrl = getenv('IVO_SETUP_URL') ?: self::URL_SETUP;
        return $setupUrl . '?' . http_build_query($params);
    }

    public function decryptIvoKey($encryptedKey)
    {
        $this->_logger->info('IVO Marketplace: Raw key input: ' . $encryptedKey);
        
        $encryptedKey = trim($encryptedKey);

        // User Requirement: "The callback key is simply Base64 encoded"
        // We attempt to decode. 
        $decoded = base64_decode($encryptedKey, true);

        // Validation: If valid UTF-8/ASCII string, use it.
        if ($decoded !== false && ctype_print($decoded)) {
             $this->_logger->info('IVO Marketplace: Base64 decoded to text: ' . $decoded);
             return $decoded;
        }

        // Fallback: If decoding produces garbage or fails, return the raw input.
        // This handles cases where the key might be passed in a different format (e.g. Hex, Raw)
        $this->_logger->info('IVO Marketplace: Base64 decode failed or non-printable. Using raw input.');
        return $encryptedKey;
    }

    /**
     * Check if API key is valid by calling /v1/merchant-api/check
     * Returns merchant info array with 'error' key if failed, or merchant data if valid
     */
    public function checkApiKey($apiKey = null)
    {
        if (!$apiKey) {
            $apiKey = $this->getApiKey();
        }
        if (!$apiKey) {
            return ['error' => 'No API key configured'];
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->getApiBaseUrl() . "/v1/merchant-api/check");
        $headers = [
            "Authorization: Bearer " . $apiKey,
            "Accept: application/json"
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0); 
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['error' => 'cURL error: ' . $curlError];
        }

        if ($httpCode !== 200) {
            return ['error' => 'HTTP ' . $httpCode . ' - ' . substr($response, 0, 200)];
        }

        $data = json_decode($response, true);
        if (isset($data['merchant_id'])) {
            return $data;
        }

        return ['error' => 'Invalid API response: ' . substr($response, 0, 200)];
    }

    public function fetchMerchantPoints($apiKey = null)
    {
        if (!$apiKey) {
            $apiKey = $this->getApiKey();
        }
        if (!$apiKey) return [];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->getApiBaseUrl() . "/v1/merchant-api/merchant-points");
        $headers = [
            "Authorization: Bearer " . $apiKey,
            "Accept: application/json"
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0); 
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

        $response = curl_exec($ch);
        $this->_logger->info('IVO RAW RESPONSE: ' . $response);
        curl_close($ch);

        $data = json_decode($response, true);
        if (is_array($data)) {
            // Normalize points if present
            if (isset($data['points']) && is_array($data['points'])) {
                foreach ($data['points'] as &$point) {
                    if (isset($point['_id']) && !isset($point['id'])) {
                        $point['id'] = $point['_id'];
                    }
                }
            }
            // Normalize data if present
            if (isset($data['data']) && is_array($data['data'])) {
                foreach ($data['data'] as &$point) {
                    if (isset($point['_id']) && !isset($point['id'])) {
                        $point['id'] = $point['_id'];
                    }
                }
            }
            // Normalize flat array if it's a list
            if (!isset($data['points']) && !isset($data['data']) && isset($data[0])) {
                foreach ($data as &$point) {
                    if (is_array($point) && isset($point['_id']) && !isset($point['id'])) {
                        $point['id'] = $point['_id'];
                    }
                }
            }
        }
        return $data;
    }

    public function getMerchantPointId()
    {
        $configId = $this->scopeConfig->getValue('ivo_marketplace/general/merchant_point_id');
        if ($configId) {
            return $configId;
        }

        $points = $this->fetchMerchantPoints();
        // Check both points and data
        $pointsList = $points['points'] ?? $points['data'] ?? $points ?? [];
        if (is_array($pointsList) && count($pointsList) > 0) {
            return $pointsList[0]['id'] ?? $pointsList[0]['_id'] ?? null;
        }
        return null;
    }

    /**
     * Prepare product payload for IVO API
     * Includes: name, price, currency, availability, merchant_point_id, merchant_internal_id,
     *           description (short_description + description + attributes), brand, category
     */
    public function prepareProductPayload($product)
    {
        $merchantPointId = $this->getMerchantPointId();
        if (!$merchantPointId) {
            return null;
        }
        
        // Get stock quantity
        try {
            $stockItem = $this->_stockRegistry->getStockItem($product->getId());
            $qty = $stockItem ? (int)$stockItem->getQty() : 0;
        } catch (\Exception $e) {
            $qty = 0;
        }
        
        $store = $this->_storeManager->getStore();
        $currencyCode = $store->getCurrentCurrencyCode();
        if (!$currencyCode) {
             $currencyCode = $store->getBaseCurrencyCode();
        }
        
        // Build description: short_description + description + attributes
        $descriptionParts = [];
        
        // Short description
        $shortDesc = $product->getShortDescription();
        if ($shortDesc) {
            $descriptionParts[] = strip_tags($shortDesc);
        }
        
        // Full description
        $fullDesc = $product->getDescription();
        if ($fullDesc) {
            $descriptionParts[] = strip_tags($fullDesc);
        }
        
        // Product attributes (custom attributes)
        $attributes = $product->getAttributes();
        foreach ($attributes as $attribute) {
            // Skip system/internal attributes
            $attrCode = $attribute->getAttributeCode();
            if (in_array($attrCode, ['name', 'description', 'short_description', 'sku', 'price', 
                'special_price', 'cost', 'weight', 'status', 'visibility', 'tax_class_id',
                'url_key', 'url_path', 'image', 'small_image', 'thumbnail', 'swatch_image',
                'meta_title', 'meta_keyword', 'meta_description', 'news_from_date', 'news_to_date',
                'special_from_date', 'special_to_date', 'quantity_and_stock_status', 'category_ids',
                'required_options', 'has_options', 'created_at', 'updated_at', 'gift_message_available',
                'media_gallery', 'gallery', 'old_id', 'page_layout', 'options_container', 'custom_design',
                'custom_design_from', 'custom_design_to', 'custom_layout_update', 'tier_price', 'msrp',
                'msrp_display_actual_price_type', 'country_of_manufacture', 'links_purchased_separately',
                'samples_title', 'links_title', 'links_exist', 'shipment_type'])) {
                continue;
            }
            
            // Only include visible attributes on frontend
            if ($attribute->getIsVisibleOnFront()) {
                $attrValue = $product->getAttributeText($attrCode);
                if (!$attrValue) {
                    $attrValue = $product->getData($attrCode);
                }
                if ($attrValue && !is_array($attrValue)) {
                    $attrLabel = $attribute->getStoreLabel() ?: $attribute->getFrontendLabel();
                    if ($attrLabel) {
                        $descriptionParts[] = $attrLabel . ': ' . $attrValue;
                    }
                }
            }
        }
        
        $description = implode("\n\n", $descriptionParts);
        
        // Get brand (manufacturer attribute or custom brand attribute)
        $brand = '';
        if ($product->getManufacturer()) {
            $brand = $product->getAttributeText('manufacturer');
        }
        if (!$brand && $product->getBrand()) {
            $brand = $product->getAttributeText('brand');
            if (!$brand) {
                $brand = $product->getBrand();
            }
        }
        
        // Get category (with full path)
        $category = '';
        $categoryIds = $product->getCategoryIds();
        if (!empty($categoryIds)) {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $categoryRepository = $objectManager->get(\Magento\Catalog\Api\CategoryRepositoryInterface::class);
            
            $categoryPaths = [];
            foreach ($categoryIds as $categoryId) {
                try {
                    $categoryObj = $categoryRepository->get($categoryId);
                    $path = $categoryObj->getPath();
                    if ($path) {
                        // Path is like "1/2/3/4" - get category names
                        $pathIds = explode('/', $path);
                        // Skip root (1) and default category (2)
                        $pathIds = array_slice($pathIds, 2);
                        
                        $pathNames = [];
                        foreach ($pathIds as $pathId) {
                            try {
                                $pathCategory = $categoryRepository->get($pathId);
                                $pathNames[] = $pathCategory->getName();
                            } catch (\Exception $e) {
                                continue;
                            }
                        }
                        if (!empty($pathNames)) {
                            $categoryPaths[] = implode(' > ', $pathNames);
                        }
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }
            
            // Use the deepest category path
            if (!empty($categoryPaths)) {
                usort($categoryPaths, function($a, $b) {
                    return substr_count($b, '>') - substr_count($a, '>');
                });
                $category = $categoryPaths[0];
            }
        }

        $payload = [
            'name' => $product->getName(),
            'price' => $this->applyPriceModifier((float)$product->getPrice()),
            'currency' => $currencyCode,
            'availability' => $qty,
            'merchant_point_id' => $merchantPointId,
            'merchant_internal_id' => $product->getSku()
        ];
        
        // Add optional fields only if they have values
        if ($description) {
            $payload['description'] = $description;
        }
        if ($brand) {
            $payload['brand'] = $brand;
        }
        if ($category) {
            $payload['category'] = $category;
        }
        
        // Get product images
        $images = $this->getProductImages($product);
        if (!empty($images)) {
            $payload['images'] = $images;
        }
        
        return $payload;
    }

    /**
     * Get product images (base + media gallery)
     * Returns array of full image URLs
     */
    public function getProductImages($product)
    {
        $images = [];
        
        try {
            $store = $this->_storeManager->getStore();
            $baseUrl = $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA) . 'catalog/product';
            
            // Main image
            $mainImage = $product->getImage();
            if ($mainImage && $mainImage !== 'no_selection') {
                $images[] = $baseUrl . $mainImage;
            }
            
            // Gallery images
            $mediaGallery = $product->getMediaGalleryImages();
            if ($mediaGallery) {
                foreach ($mediaGallery as $image) {
                    $url = $image->getUrl();
                    if ($url && !in_array($url, $images)) {
                        $images[] = $url;
                    }
                }
            }
        } catch (\Exception $e) {
            // Silently ignore image errors
        }
        
        return $images;
    }

    public function syncProducts($productsData, $mode = 'async')
    {
         $apiKey = $this->getApiKey();
        if (!$apiKey) return false;

        $payload = [
            "mode" => $mode,
            "import_name" => "api_sync_import_magento",
            "products" => $productsData
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->getApiBaseUrl() . "/v1/merchant-api/product/sync-multiple");
        $headers = [
            "Authorization: Bearer " . $apiKey,
            "Accept: application/json",
            "Content-Type: application/json"
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0); 
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return ['code' => $httpCode, 'response' => json_decode($response, true)];
    }

    public function getProductIvoInfo($sku)
    {
         $apiKey = $this->getApiKey();
        if (!$apiKey) return null;

        $url = $this->getApiBaseUrl() . "/v1/merchant-api/product/info-by-sku";
        $payload = [
            "import_name" => "api_sync_import_magento",
            "merchant_internal_id" => $sku
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        $headers = [
            "Authorization: Bearer " . $apiKey,
            "Accept: application/json",
            "Content-Type: application/json"
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0); 
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

        $response = curl_exec($ch);
        curl_close($ch);
        
        return json_decode($response, true);
    }

    public function getLogger()
    {
        return $this->_logger;
    }

    /**
     * Update local product stock (called by inbound IVO webhook when a sale happens on IVO).
     *
     * @param string $sku
     * @param int    $qty
     * @throws \Exception when the product cannot be found or stock cannot be saved
     */
    public function setProductStock($sku, $qty)
    {
        if ($sku === null || $sku === '') {
            throw new \InvalidArgumentException('SKU is required');
        }
        $qty = (int)$qty;
        if ($qty < 0) {
            $qty = 0;
        }

        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $productRepository = $objectManager->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);

        try {
            $product = $productRepository->get($sku);
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            throw new \Exception('Product not found: ' . $sku);
        }

        $stockItem = $this->_stockRegistry->getStockItem($product->getId());
        $stockItem->setQty($qty);
        $stockItem->setIsInStock($qty > 0);
        $this->_stockRegistry->updateStockItemBySku($sku, $stockItem);
    }
}
