<?php

namespace Ivo\Marketplace\Controller\Adminhtml\Sync;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Ivo\Marketplace\Helper\Config;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;

/**
 * Force Sync, one slice per request: the config page calls again with the
 * returned `after` and `run_id` until `done`.
 *
 * A whole catalogue in one request outlives shop timeouts (often 30 s), so
 * each request sends only the next SLICE_PRODUCTS products by id. Every
 * slice carries the same run id and the last one closes the run, so IVO
 * zeroes what the run never mentioned. A failed slice stops the walk before
 * that, so a partial run never zeroes products that merely did not arrive.
 * Paging by id, not offset, keeps a product added or deleted mid-run from
 * shifting the pages and skipping a neighbour.
 */
class All extends Action implements HttpPostActionInterface
{
    const SLICE_PRODUCTS = 50;

    protected $_ivoHelper;
    protected $_productCollectionFactory;
    protected $_productRepository;
    protected $_resultJsonFactory;

    public function __construct(
        Context $context,
        Config $helper,
        CollectionFactory $productCollectionFactory,
        ProductRepositoryInterface $productRepository,
        JsonFactory $resultJsonFactory
    ) {
        $this->_ivoHelper = $helper;
        $this->_productCollectionFactory = $productCollectionFactory;
        $this->_productRepository = $productRepository;
        $this->_resultJsonFactory = $resultJsonFactory;
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->_resultJsonFactory->create();

        try {
            $after = max(0, (int)$this->getRequest()->getParam('after', 0));
            $runId = preg_replace('/[^A-Za-z0-9_]/', '', (string)$this->getRequest()->getParam('run_id', ''));

            if (!$this->_ivoHelper->getApiKey()) {
                return $result->setData(['error' => (string)__('IVO Marketplace is not configured.')]);
            }
            if (!$this->_ivoHelper->getMerchantPointId()) {
                return $result->setData(['error' => (string)__('No Merchant Point is selected. Choose one in the settings below and click Save Config.')]);
            }

            if ($runId === '') {
                $runId = 'mg_' . time() . '_' . substr(md5(uniqid((string)mt_rand(), true)), 0, 12);
            }

            $collection = $this->createOfferCollection();
            $collection->addAttributeToSelect('*');
            $collection->addAttributeToFilter('entity_id', ['gt' => $after]);
            $collection->setOrder('entity_id', 'ASC');
            $collection->setPageSize(self::SLICE_PRODUCTS);
            $collection->setCurPage(1);

            $payloads = [];
            $consumed = 0;
            foreach ($collection as $product) {
                $after = (int)$product->getId();
                $consumed++;

                // Load full product to get all attributes
                try {
                    $fullProduct = $this->_productRepository->getById($after);
                } catch (\Exception $e) {
                    $fullProduct = $product;
                }

                $payload = $this->_ivoHelper->prepareProductPayload($fullProduct);
                if (!$this->_ivoHelper->isValidSyncPayload($payload)) {
                    $this->_ivoHelper->getLogger()->warning(
                        'IVO Marketplace Force Sync: skipped product ' . $fullProduct->getSku() . ' (id ' . $after . '):'
                        . ' a non-empty name and a price of at least 0.01 are required.'
                    );
                    continue;
                }
                $payloads[] = $payload;
            }

            foreach (array_chunk($payloads, Config::SYNC_BATCH_LIMIT) as $batch) {
                $sync = $this->_ivoHelper->syncProducts($batch, 'async', $runId);
                if (!$this->_ivoHelper->isSyncSuccess($sync)) {
                    // The page sends the same slice again on `retry`; the run stays open
                    return $result->setData([
                        'error' => $this->_ivoHelper->getSyncErrorMessage($sync, $batch),
                        'retry' => $this->_ivoHelper->isRetryableSyncFailure($sync),
                    ]);
                }
            }

            $done = $consumed < self::SLICE_PRODUCTS;
            $data = [
                'done' => $done,
                'after' => $after,
                'run_id' => $runId,
                'position' => (int)$this->createOfferCollection()->addAttributeToFilter('entity_id', ['lteq' => $after])->getSize(),
                'total' => (int)$this->createOfferCollection()->getSize(),
            ];

            if ($done) {
                // Every price is out: a pending re-send (new price modifier) is
                // done if this run started after it was asked for
                $parts = explode('_', $runId);
                $this->_ivoHelper->clearResyncRequest(isset($parts[1]) ? (int)$parts[1] : 0);

                $complete = $this->_ivoHelper->completeSyncRun($runId);
                if (!$this->_ivoHelper->isSyncSuccess($complete)) {
                    $data['error'] = (string)__(
                        'All products were sent, but IVO did not close the sync run, so products deleted from the shop stay on IVO until the next Force Sync. %1',
                        $this->_ivoHelper->getSyncErrorMessage($complete)
                    );
                    // Sending the last slice again closes the run again
                    $data['retry'] = $this->_ivoHelper->isRetryableSyncFailure($complete);
                    return $result->setData($data);
                }
                $data['success'] = (string)__('Products synchronization started successfully.');
            }

            return $result->setData($data);
        } catch (\Exception $e) {
            $this->_ivoHelper->getLogger()->error('IVO Marketplace Force Sync Error: ' . $e->getMessage());
            return $result->setData(['error' => $e->getMessage()]);
        }
    }

    /**
     * The products a full sync sends: everything but the container types,
     * whose simple products are the offers.
     */
    private function createOfferCollection()
    {
        $collection = $this->_productCollectionFactory->create();
        $collection->addAttributeToFilter('type_id', ['nin' => Config::PARENT_PRODUCT_TYPES]);
        return $collection;
    }
}
