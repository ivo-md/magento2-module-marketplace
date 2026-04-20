<?php

namespace Ivo\Marketplace\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\ProductMetadataInterface;

class Data extends AbstractHelper
{
    const CONFIG_PATH_API_KEY = 'ivo_marketplace/general/api_key';
    const CONFIG_PATH_CONFIGURED = 'ivo_marketplace/general/configured';
    
    // IVO Endpoints
    const URL_SETUP = 'https://www.ivo.md/merchant/plugin/setup';
    const API_BASE_URL = 'https://api-web:8443';

    protected $_storeManager;
    protected $_configWriter;
    protected $_cacheTypeList;
    protected $_productMetadata;

    public function __construct(
        Context $context,
        StoreManagerInterface $storeManager,
        WriterInterface $configWriter,
        TypeListInterface $cacheTypeList,
        ProductMetadataInterface $productMetadata
    ) {
        $this->_storeManager = $storeManager;
        $this->_configWriter = $configWriter;
        $this->_cacheTypeList = $cacheTypeList;
        $this->_productMetadata = $productMetadata;
        parent::__construct($context);
    }

    public function isConfigured()
    {
        return $this->scopeConfig->getValue(self::CONFIG_PATH_CONFIGURED);
    }

    public function getApiKey()
    {
        return $this->scopeConfig->getValue(self::CONFIG_PATH_API_KEY);
    }

    public function saveApiKey($apiKey)
    {
        $this->_configWriter->save(self::CONFIG_PATH_API_KEY, $apiKey);
        $this->_configWriter->save(self::CONFIG_PATH_CONFIGURED, 1);
        $this->_cacheTypeList->cleanType('config');
    }

    public function getSetupUrl()
    {
        $baseUrl = $this->_storeManager->getStore()->getBaseUrl();
        $returnUrl = $this->_urlBuilder->getUrl('ivo_marketplace/setup/callback');
        
        $params = [
            'url_shop' => $baseUrl,
            'url_return' => $returnUrl,
            'platform' => 'magento',
            'version' => '1.0.5',
            'server_ip' => $_SERVER['SERVER_ADDR'] ?? '127.0.0.1',
            'server_os' => php_uname('s')
        ];

        return self::URL_SETUP . '?' . http_build_query($params);
    }

    public function decryptIvoKey($encryptedKey)
    {
        $key = "P@u8!icK3y_F0r_P!ug1ns_&_3xt3rn@l_U53_0nly_D0_N0t_U53_F0r_D8";
        $iv  = "P@u8!icS@lt_2026"; 

        $tmp = str_replace("!", "+", $encryptedKey);
        $tmp = str_replace("~", "/", $tmp);

        $tmp = base64_decode($tmp);

        $decrypted = openssl_decrypt($tmp, "AES-256-CBC", $key, OPENSSL_RAW_DATA, $iv);

        return $decrypted;
    }

    public function fetchMerchantPoints()
    {
        $apiKey = $this->getApiKey();
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

    public function syncProducts($productsData, $mode = 'async')
    {
         $apiKey = $this->getApiKey();
        if (!$apiKey) return false;

        $payload = [
            "mode" => $mode,
            "import_name" => "Import Magento",
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

    public function getProductIvoInfo($sku)
    {
         $apiKey = $this->getApiKey();
        if (!$apiKey) return null;

        $payload = [
            "import_name" => "Check Status Magento",
            "merchant_internal_id" => $sku
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, self::API_BASE_URL . "/v1/merchant-api/product/info-by-sku");
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
}
