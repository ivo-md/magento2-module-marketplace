<?php
/**
 * Copyright © IVO. All rights reserved.
 *
 * Inbound webhook — IVO moved this shop's stock.
 *
 * Endpoint:  POST /ivo_marketplace_webhook/stock/update
 * Auth:      Authorization: Bearer <api_key>      (matches stored API key)
 * Body:      { "products": [ { "merchant_internal_id": "SKU", "availability": -2 }, ... ] }
 *
 * `availability` is a movement, not a total: negative when a sale on IVO took
 * units, positive when a cancelled IVO order gave them back. It is the path
 * IVO has always called, so only modules from 1.0.12 on answer it; older ones
 * 404 there instead of overwriting their stock with a movement.
 */

namespace Ivo\Marketplace\Controller\Stock;

use Ivo\Marketplace\Controller\Webhook\Stock;

class Update extends Stock
{
    protected function applyItem(string $sku, int $availability): array
    {
        $qty = $this->_ivoHelper->adjustProductStock($sku, $availability);
        if ($qty === null) {
            // Unmanaged stock has no count to move
            return ['merchant_internal_id' => $sku, 'note' => 'Skipped (stock not managed)'];
        }
        return ['merchant_internal_id' => $sku, 'availability' => $qty];
    }
}
