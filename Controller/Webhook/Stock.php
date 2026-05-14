<?php
/**
 * Copyright © IVO. All rights reserved.
 *
 * Inbound webhook controller — receives stock updates from IVO.
 *
 * Endpoint:  POST /ivo_marketplace_webhook/stock/update
 * Auth:      Authorization: Bearer <api_key>      (matches stored API key)
 * Body:      { "products": [ { "merchant_internal_id": "SKU", "availability": 5 }, ... ] }
 *
 * Triggered when a sale occurs on IVO so the merchant's local stock stays in sync.
 */

namespace Ivo\Marketplace\Controller\Webhook;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Ivo\Marketplace\Helper\Config;

class Stock extends Action implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /** @var Config */
    protected $_ivoHelper;

    /** @var JsonFactory */
    protected $_jsonFactory;

    public function __construct(
        Context $context,
        Config $helper,
        JsonFactory $jsonFactory
    ) {
        $this->_ivoHelper = $helper;
        $this->_jsonFactory = $jsonFactory;
        parent::__construct($context);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    public function execute()
    {
        $result = $this->_jsonFactory->create();
        $logger = $this->_ivoHelper->getLogger();

        // Auth
        $bearer = $this->extractBearerToken();
        $expected = $this->_ivoHelper->getApiKey();

        if (!$expected || !$bearer || !hash_equals((string)$expected, (string)$bearer)) {
            $logger->warning('IVO Marketplace Webhook: unauthorized stock update attempt');
            return $result->setHttpResponseCode(401)
                ->setData(['success' => false, 'error' => 'Unauthorized']);
        }

        // Parse JSON body
        $raw = $this->getRequest()->getContent();
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['products']) || !is_array($data['products'])) {
            return $result->setHttpResponseCode(400)
                ->setData(['success' => false, 'error' => 'Invalid payload: expected { products: [...] }']);
        }

        $updated = [];
        $errors = [];

        foreach ($data['products'] as $item) {
            $sku = isset($item['merchant_internal_id']) ? (string)$item['merchant_internal_id'] : '';
            if ($sku === '' || !array_key_exists('availability', $item)) {
                $errors[] = ['merchant_internal_id' => $sku, 'error' => 'Missing merchant_internal_id or availability'];
                continue;
            }
            $qty = (int)$item['availability'];

            try {
                $this->_ivoHelper->setProductStock($sku, $qty);
                $updated[] = ['merchant_internal_id' => $sku, 'availability' => $qty];
            } catch (\Exception $e) {
                $logger->error('IVO Marketplace Webhook: failed to update stock for ' . $sku . ' - ' . $e->getMessage());
                $errors[] = ['merchant_internal_id' => $sku, 'error' => $e->getMessage()];
            }
        }

        return $result->setData([
            'success' => empty($errors),
            'updated' => $updated,
            'errors' => $errors,
        ]);
    }

    private function extractBearerToken(): ?string
    {
        $header = $this->getRequest()->getHeader('Authorization');
        if (!$header) {
            return null;
        }
        if (stripos($header, 'Bearer ') === 0) {
            return trim(substr($header, 7));
        }
        return trim($header);
    }
}
