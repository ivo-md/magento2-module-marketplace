<?php

namespace Ivo\Marketplace\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\FlagManager;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;

class Config extends AbstractHelper
{
    const CONFIG_PATH_API_KEY = 'ivo_marketplace/general/api_key';
    const CONFIG_PATH_CONFIGURED = 'ivo_marketplace/general/configured';
    const CONFIG_PATH_MERCHANT_POINT_ID = 'ivo_marketplace/general/merchant_point_id';
    const CONFIG_PATH_PRICE_MODIFIER = 'ivo_marketplace/general/price_modifier';
    const CONFIG_PATH_UPDATE_STOCK = 'ivo_marketplace/general/update_stock';

    // Set when the whole catalogue must be re-sent (new price modifier); holds the request time
    const FLAG_RESYNC_REQUESTED_AT = 'ivo_marketplace_resync_requested_at';

    // Containers: their simple products are what is sold and what IVO gets.
    // Bundles stay: a fixed-price bundle is an offer, a dynamic-price one
    // carries price 0 and is left out by isValidSyncPayload.
    const PARENT_PRODUCT_TYPES = ['configurable', 'grouped'];

    // Stock that is not managed is not limited (the WooCommerce plugin sends the same)
    const UNMANAGED_STOCK_AVAILABILITY = 9999;

    // IVO takes at most this many products in one sync-multiple call
    const SYNC_BATCH_LIMIT = 100;

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
    protected $_flagManager;

    // The point picked when none is stored, resolved once per request (false = not yet)
    private $_fallbackMerchantPointId = false;

    public function __construct(
        Context $context,
        StoreManagerInterface $storeManager,
        WriterInterface $configWriter,
        TypeListInterface $cacheTypeList,
        ProductMetadataInterface $productMetadata,
        EncryptorInterface $encryptor,
        StockRegistryInterface $stockRegistry,
        FlagManager $flagManager
    ) {
        $this->_storeManager = $storeManager;
        $this->_configWriter = $configWriter;
        $this->_cacheTypeList = $cacheTypeList;
        $this->_productMetadata = $productMetadata;
        $this->_encryptor = $encryptor;
        $this->_stockRegistry = $stockRegistry;
        $this->_flagManager = $flagManager;
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

    public function isUpdateStockEnabled()
    {
        return (bool) $this->scopeConfig->getValue(self::CONFIG_PATH_UPDATE_STOCK);
    }

    /**
     * Ask for the whole catalogue to be re-sent. The config page sees the flag
     * and runs the sliced Force Sync; nothing catalogue-wide runs in the
     * request that raised it.
     */
    public function requestResync()
    {
        $this->_flagManager->saveFlag(self::FLAG_RESYNC_REQUESTED_AT, time());
    }

    public function isResyncRequested()
    {
        return (bool)$this->_flagManager->getFlagData(self::FLAG_RESYNC_REQUESTED_AT);
    }

    /**
     * A full run finished: the re-send it stood for is done, unless the run
     * began before the request (its early slices went out with old prices).
     */
    public function clearResyncRequest($runStartedAt)
    {
        $requestedAt = (int)$this->_flagManager->getFlagData(self::FLAG_RESYNC_REQUESTED_AT);
        if ($requestedAt && (int)$runStartedAt >= $requestedAt) {
            $this->_flagManager->deleteFlag(self::FLAG_RESYNC_REQUESTED_AT);
        }
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
            'version' => $this->_productMetadata->getVersion(),
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

    /**
     * The merchant's points as a list of arrays with an 'id', or null when IVO
     * could not be asked or did not answer with a point list (an error, a
     * timeout). An empty list is a real answer: the account has no points.
     */
    public function getMerchantPointList($apiKey = null)
    {
        $response = $this->fetchMerchantPoints($apiKey);
        if (!is_array($response)) {
            return null;
        }

        if (isset($response['points']) && is_array($response['points'])) {
            $list = $response['points'];
        } elseif (isset($response['data']) && is_array($response['data'])) {
            $list = $response['data'];
        } elseif ($response === [] || isset($response[0])) {
            $list = $response;
        } else {
            return null;
        }

        $points = [];
        foreach ($list as $point) {
            if (is_array($point) && isset($point['id']) && is_scalar($point['id']) && (string)$point['id'] !== '') {
                $points[] = $point;
            }
        }
        return $points;
    }

    public function getStoredMerchantPointId()
    {
        return trim((string)$this->scopeConfig->getValue(self::CONFIG_PATH_MERCHANT_POINT_ID));
    }

    /**
     * The point pushes go to: the stored one, or the account's only point.
     * With several points and none chosen, guessing the first one put stock
     * on a point the merchant never picked, so nothing is sent until one is.
     */
    public function getMerchantPointId()
    {
        $configId = $this->getStoredMerchantPointId();
        if ($configId !== '') {
            return $configId;
        }

        // Once per request: a full-sync slice asks for every product
        if ($this->_fallbackMerchantPointId === false) {
            $points = $this->getMerchantPointList();
            if (is_array($points) && count($points) === 1) {
                $this->_fallbackMerchantPointId = (string)$points[0]['id'];
            } else {
                $this->_fallbackMerchantPointId = null;
                $this->_logger->warning(
                    'IVO Marketplace: no Merchant Point selected'
                    . (is_array($points) ? ' (the account has ' . count($points) . ' points)' : ' (the point list could not be fetched)')
                    . '; nothing is pushed to IVO until one is chosen in Stores > Configuration > IVO Marketplace.'
                );
            }
        }
        return $this->_fallbackMerchantPointId;
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

        $qty = $this->getAvailability($product);

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

        // Selling price now, and the reference price above it when there is one
        $prices = $this->getProductPrices($product);

        $payload = [
            'name' => $product->getName(),
            'price' => $this->applyPriceModifier($prices['price']),
            'currency' => $currencyCode,
            'availability' => $qty,
            'merchant_point_id' => $merchantPointId,
            'merchant_internal_id' => $product->getSku()
        ];

        // Add optional fields only if they have values
        if (isset($prices['retail_price'])) {
            $payload['retail_price'] = $prices['retail_price'];
        }
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

        // Weight, only when set
        $payload = array_merge($payload, $this->getProductMeasures($product));
        
        return $payload;
    }

    /**
     * Configurable and grouped products are containers: the simple products
     * under them are what is sold, and those are the offers. Sent themselves
     * they carry price 0, and one such item makes IVO reject its batch.
     */
    public function isSentAsOffer($product)
    {
        return !in_array((string)$product->getTypeId(), self::PARENT_PRODUCT_TYPES, true);
    }

    /**
     * IVO rejects a whole sync-multiple call for one item without a name or
     * with a price under 0.01, so such items are left out (and logged by the
     * caller) instead of taking their neighbours down with them.
     */
    public function isValidSyncPayload($payload)
    {
        return is_array($payload)
            && isset($payload['name'])
            && is_string($payload['name'])
            && trim($payload['name']) !== ''
            && isset($payload['price'])
            && is_numeric($payload['price'])
            && (float)$payload['price'] >= 0.01;
    }

    /**
     * Units IVO may sell: the shop's real stock, never more.
     *
     * A disabled product, or a variant whose configurable product is
     * disabled, is not for sale. Out of stock is 0 whatever the qty says, and
     * backorders (negative qty) are 0. Stock that is not managed but in stock
     * is UNMANAGED_STOCK_AVAILABILITY. This reads the legacy stock item: MSI
     * salable quantity (reservations of unshipped orders) is not consulted.
     */
    public function getAvailability($product)
    {
        if ((int)$product->getStatus() === Status::STATUS_DISABLED) {
            return 0;
        }
        if ($this->hasDisabledConfigurableParent($product)) {
            return 0;
        }

        try {
            $stockItem = $this->_stockRegistry->getStockItem($product->getId());
        } catch (\Exception $e) {
            return 0;
        }
        if (!$stockItem || !$stockItem->getIsInStock()) {
            return 0;
        }
        if (!$stockItem->getManageStock()) {
            return self::UNMANAGED_STOCK_AVAILABILITY;
        }

        return max(0, (int)$stockItem->getQty());
    }

    /**
     * Whether a configurable product this one is a variant of is disabled:
     * the shop then sells none of its variants, whatever their own stock.
     * Resolved lazily so the module keeps working without ConfigurableProduct.
     */
    private function hasDisabledConfigurableParent($product)
    {
        $productId = (int)$product->getId();
        $linkResource = \Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable::class;
        if (!$productId || !class_exists($linkResource)) {
            return false;
        }

        try {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $parentIds = $objectManager->get($linkResource)->getParentIdsByChild($productId);
            if (empty($parentIds)) {
                return false;
            }

            $productResource = $objectManager->get(\Magento\Catalog\Model\ResourceModel\Product::class);
            $storeId = (int)$product->getStoreId();
            foreach ($parentIds as $parentId) {
                $status = $productResource->getAttributeRawValue($parentId, 'status', $storeId);
                if (is_scalar($status) && (int)$status === Status::STATUS_DISABLED) {
                    return true;
                }
            }
        } catch (\Exception $e) {
            $this->_logger->warning('IVO Marketplace: could not read the configurable parents of product ' . $productId . ': ' . $e->getMessage());
        }

        return false;
    }

    /**
     * The price the shop sells at right now, plus the reference price IVO
     * shows above it as the recommended retail price.
     *
     * Selling price: the special price while it is active in its date window,
     * through the product type's own price model (a bundle special is a
     * percentage). Catalog price rules stay out: Magento applies them per
     * area and customer group, so an admin sync and a checkout-triggered one
     * would push different prices.
     *
     * Reference price: the MSRP when set, otherwise the regular price while a
     * special undercuts it; only when above the selling price, and never
     * through the price modifier.
     */
    public function getProductPrices($product)
    {
        // Without a qty, getBasePrice() is the regular price with the special applied
        $sellingPrice = (float)$product->getPriceModel()->getBasePrice($product);
        $prices = ['price' => $sellingPrice];

        $msrp = $product->getData('msrp');
        $msrp = is_numeric($msrp) ? round((float)$msrp, 2) : 0.0;
        $regularPrice = round((float)$product->getPrice(), 2);
        if ($msrp > $sellingPrice) {
            $prices['retail_price'] = $msrp;
        } elseif ($regularPrice > $sellingPrice) {
            $prices['retail_price'] = $regularPrice;
        }

        return $prices;
    }

    /**
     * Product weight with its unit spelled out (IVO converts units itself).
     * Magento has no native package dimensions, so only weight is sent;
     * an empty or zero weight is left out.
     */
    public function getProductMeasures($product)
    {
        $weight = $product->getWeight();
        if (!is_numeric($weight)) {
            return [];
        }
        $weight = round((float)$weight, 4);
        if ($weight <= 0) {
            return [];
        }

        // general/locale/weight_unit is 'kgs' or 'lbs'
        $units = ['kgs' => 'kg', 'kg' => 'kg', 'lbs' => 'lbs', 'lb' => 'lbs'];
        $unit = strtolower((string)$this->scopeConfig->getValue(
            'general/locale/weight_unit',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        ));
        if (!isset($units[$unit])) {
            return [];
        }

        return ['weight' => rtrim(rtrim(number_format($weight, 4, '.', ''), '0'), '.') . ' ' . $units[$unit]];
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

    public function syncProducts($productsData, $mode = 'async', $integrationRunId = null)
    {
         $apiKey = $this->getApiKey();
        if (!$apiKey) return false;

        $payload = [
            "mode" => $mode,
            "import_name" => "api_sync_import_magento",
            "products" => $productsData
        ];
        if ($integrationRunId) {
            $payload["integration_run_id"] = $integrationRunId;
        }

        $what = 'sync of ' . count($productsData) . ' product(s)' . ($integrationRunId ? ' in run ' . $integrationRunId : '');
        return $this->postToIvo('/v1/merchant-api/product/sync-multiple', $payload, $apiKey, $what);
    }

    /**
     * Close a full-sync run: products IVO knows from this store that were NOT
     * part of the run no longer exist here, so their offers go out of stock.
     */
    public function completeSyncRun($integrationRunId)
    {
        $apiKey = $this->getApiKey();
        if (!$apiKey || !$integrationRunId) return false;

        $payload = [
            "import_name" => "api_sync_import_magento",
            "integration_run_id" => $integrationRunId
        ];

        return $this->postToIvo('/v1/merchant-api/product/complete-run', $payload, $apiKey, 'completion of run ' . $integrationRunId);
    }

    /**
     * POST a JSON body to the IVO API. A failure is logged here, so no push
     * that IVO never got or refused goes unnoticed; the caller decides what
     * the admin sees.
     *
     * @return array ['code' => int, 'response' => mixed, 'error' => string cURL error]
     */
    private function postToIvo($path, array $payload, $apiKey, $what)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->getApiBaseUrl() . $path);
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
        // An unreachable IVO must not hold a save, a checkout or a sync slice open
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = (string)curl_error($ch);
        curl_close($ch);

        $body = is_string($response) ? $response : '';
        if ($curlError !== '') {
            $this->_logger->error('IVO Marketplace: ' . $what . ' failed: cURL error: ' . $curlError);
        } elseif ($httpCode < 200 || $httpCode >= 300) {
            $this->_logger->error('IVO Marketplace: ' . $what . ' rejected: HTTP ' . $httpCode . ' ' . substr($body, 0, 1000));
        }

        return ['code' => $httpCode, 'response' => json_decode($body, true), 'error' => $curlError];
    }

    public function isSyncSuccess($result)
    {
        $code = is_array($result) ? (int)($result['code'] ?? 0) : 0;
        return $code >= 200 && $code < 300;
    }

    /**
     * Whether sending the same call again may succeed: IVO was unreachable or
     * failed on its side. A 4xx is an answer about the data and stays one.
     */
    public function isRetryableSyncFailure($result)
    {
        if (!is_array($result)) {
            return false;
        }
        $code = (int)($result['code'] ?? 0);
        return ($result['error'] ?? '') !== '' || $code === 0 || $code === 429 || $code >= 500;
    }

    /**
     * What the admin is told about a failed call: the HTTP code, IVO's message
     * and, for a validation error, which product of the batch it was about.
     */
    public function getSyncErrorMessage($result, array $productsPayload = [])
    {
        if (!is_array($result)) {
            return (string)__('IVO Marketplace is not configured.');
        }
        if (($result['error'] ?? '') !== '') {
            return (string)__('Could not reach IVO: %1', $result['error']);
        }

        $message = (string)__('Sync failed. API Code: %1', (int)($result['code'] ?? 0));
        $response = $result['response'] ?? null;
        if (!is_array($response)) {
            return $message;
        }

        $apiMessage = $response['message'] ?? $response['error'] ?? '';
        if (is_string($apiMessage) && $apiMessage !== '') {
            $message .= ' ' . $apiMessage;
        }

        $parts = [];
        if (isset($response['index']) && is_numeric($response['index'])) {
            $index = (int)$response['index'];
            $label = 'batch item #' . ($index + 1);
            $item = $productsPayload[$index] ?? null;
            if (is_array($item) && trim((string)($item['merchant_internal_id'] ?? '')) !== '') {
                $label = 'product ' . trim((string)$item['merchant_internal_id']);
            }
            $parts[] = $label;
        }
        if (isset($response['errors']) && is_array($response['errors'])) {
            foreach ($response['errors'] as $field => $messages) {
                $messages = array_values(array_filter(array_map(function ($m) {
                    return is_scalar($m) ? (string)$m : '';
                }, (array)$messages)));
                if (!empty($messages)) {
                    $parts[] = $field . ': ' . implode(', ', $messages);
                }
            }
        }
        if (!empty($parts)) {
            $message .= ' - ' . implode('; ', $parts);
        }

        return $message;
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
    /**
     * Move a product's stock by $delta (a sale on IVO is negative, a cancelled
     * IVO order positive) and return the new quantity, or null when the
     * product's stock is not managed and there is nothing to move.
     */
    public function adjustProductStock($sku, $delta)
    {
        if ($sku === null || $sku === '') {
            throw new \InvalidArgumentException('SKU is required');
        }

        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $productRepository = $objectManager->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);

        try {
            $product = $productRepository->get($sku);
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            throw new \Exception('Product not found: ' . $sku);
        }

        $stockItem = $this->_stockRegistry->getStockItem($product->getId());
        if (!$stockItem->getManageStock()) {
            return null;
        }

        $qty = max(0, (int)$stockItem->getQty() + (int)$delta);
        $stockItem->setQty($qty);
        $stockItem->setIsInStock($qty > 0);
        $this->_stockRegistry->updateStockItemBySku($sku, $stockItem);

        return $qty;
    }

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
