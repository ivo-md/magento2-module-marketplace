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
    
    // IVO Endpoints - Hardcoded, not user-configurable
    const URL_SETUP = 'http://localhost:83/merchant/plugin/setup';
    const API_BASE_URL = 'https://api-web:8443';

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
            'version' => '1.0.0',
            'ip_server' => $_SERVER['SERVER_ADDR'] ?? '127.0.0.1',
            'os_server' => php_uname('s')
        ];

        return self::URL_SETUP . '?' . http_build_query($params);
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

    public function fetchMerchantPoints($apiKey = null)
    {
        if (!$apiKey) {
            $apiKey = $this->getApiKey();
        }
        if (!$apiKey) return [];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, self::API_BASE_URL . "/v1/merchant-api/merchant-points");
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

        return json_decode($response, true);
    }

    public function getMerchantPointId()
    {
        $configId = $this->scopeConfig->getValue('ivo_marketplace/general/merchant_point_id');
        if ($configId) {
            return $configId;
        }

        $points = $this->fetchMerchantPoints();
        // Assuming structure { "data": [ { "id": "...", ... } ] }
        if (isset($points['data']) && is_array($points['data']) && count($points['data']) > 0) {
            return $points['data'][0]['id']; 
        }
        return null;
    }

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

        return [
            'name' => $product->getName(),
            'price' => (float)$product->getPrice(),
            'currency' => $currencyCode,
            'availability' => $qty,
            'merchant_point_id' => $merchantPointId,
            'merchant_internal_id' => $product->getSku()
        ];
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
        curl_setopt($ch, CURLOPT_URL, self::API_BASE_URL . "/v1/merchant-api/product/sync-multiple");
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

    /**
     * Check if the API key is valid by calling /v1/merchant-api/check
     * @param string|null $apiKey - if null, uses stored API key
     * @return array - returns merchant info array with 'error' key if failed, or merchant data if valid
     */
    public function checkApiKey($apiKey = null)
    {
        if ($apiKey === null) {
            $apiKey = $this->getApiKey();
        }
        if (!$apiKey) {
            return ['error' => 'No API key configured'];
        }

        $url = self::API_BASE_URL . "/v1/merchant-api/check";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        $headers = [
            "Authorization: Bearer " . $apiKey,
            "Accept: application/json",
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
        if (!$data || !isset($data['merchant_id'])) {
            return ['error' => 'Invalid API response: ' . substr($response, 0, 200)];
        }

        return $data;
    }

    public function getProductIvoInfo($sku)
    {
         $apiKey = $this->getApiKey();
        if (!$apiKey) return null;

        $url = self::API_BASE_URL . "/v1/merchant-api/product/info-by-sku";
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
}
