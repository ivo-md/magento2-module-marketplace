<?php
namespace Ivo\Marketplace\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Ivo\Marketplace\Helper\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;

class Actions extends Field
{
    protected $_ivoHelper;
    protected $scopeConfig;

    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        Config $helper,
        array $data = []
    ) {
        $this->_ivoHelper = $helper;
        $this->scopeConfig = $context->getScopeConfig();
        parent::__construct($context, $data);
    }

    public function render(AbstractElement $element)
    {
        return '<tr id="row_' . $element->getHtmlId() . '"><td colspan="3" style="padding: 20px 0;">' . $this->_getElementHtml($element) . '</td></tr>';
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        $isConfigured = $this->_ivoHelper->isConfigured();
        $apiKey = $this->_ivoHelper->getApiKey();
        $updateStock = $this->scopeConfig->getValue('ivo_marketplace/general/update_stock');
        
        if (!$apiKey) {
            $isConfigured = false;
        }
        
        $merchantInfo = [];
        $apiError = '';
        if ($isConfigured) {
            $merchantInfo = $this->_ivoHelper->checkApiKey();
            if (isset($merchantInfo['error'])) {
                $apiError = $merchantInfo['error'];
            }
        }
        
        $urlSetup = $this->getUrl('ivo_marketplace/setup/index');
        $urlSync = $this->getUrl('ivo_marketplace/sync/all');

        $html = '<style>
.ivo-card, .ivo-card * {
  box-sizing: border-box;
}
.ivo-card {
  --ivo-pink: #c711bd;
  --ivo-purple: #8a24d6;
  --ivo-blue: #273bd8;
  --ivo-ink: #101828;
  --ivo-muted: #667085;
  position: relative;
  overflow: hidden;
  width: 100%;
  max-width: 100%;
  min-height: 410px;
  border: 1px solid #dcdcde;
  border-radius: 6px;
  background:
    radial-gradient(circle at 82% 10%, rgba(199, 17, 189, .14), transparent 30%),
    radial-gradient(circle at 12% 90%, rgba(39, 59, 216, .12), transparent 34%),
    #fff;
  box-shadow: 0 1px 1px rgba(0, 0, 0, .04);
  margin: 0 auto 20px;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
  text-align: left;
}
.ivo-card__head {
  min-height: 56px;
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 0 22px;
  border-bottom: 1px solid #e5e7eb;
  background: rgba(255, 255, 255, .76);
}
.ivo-card__icon {
  width: 28px;
  height: 28px;
  border-radius: 8px;
  display: grid;
  place-items: center;
  color: #fff;
  background: linear-gradient(135deg, var(--ivo-pink), var(--ivo-blue));
  font-size: 15px;
}
.ivo-card__status {
  margin-left: auto;
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 6px 10px;
  border-radius: 999px;
  background: #fff1fb;
  color: #9d1698;
  font-size: 12px;
  font-weight: 700;
  border: 1px solid #f4c7ef;
}
.ivo-card__status--connected {
  background: #ecfdf5;
  color: #047857;
  border-color: #a7f3d0;
}
.ivo-card__status-dot {
  width: 8px;
  height: 8px;
  border-radius: 99px;
  background: var(--ivo-pink);
  box-shadow: 0 0 0 4px rgba(199, 17, 189, .14);
}
.ivo-card__status-dot--connected {
  background: #10b981;
  box-shadow: 0 0 0 4px rgba(16, 185, 129, .14);
}
.ivo-card__body {
  display: flex;
  flex-wrap: wrap;
  gap: 36px;
  align-items: stretch;
  padding: 24px;
}
.ivo-card__body > div:first-child {
  flex: 1 1 300px;
  min-width: 0;
}
.ivo-card__logo {
  width: 76px;
  height: 76px;
  border-radius: 20px;
  display: grid;
  place-items: center;
  color: #fff;
  font-weight: 900;
  font-size: 26px;
  background: linear-gradient(135deg, var(--ivo-pink), var(--ivo-purple) 48%, var(--ivo-blue));
  box-shadow: 0 18px 40px rgba(39, 59, 216, .26);
  margin-bottom: 22px;
}
.ivo-card__eyebrow {
  margin: 0 0 10px;
  color: var(--ivo-purple);
  font-size: 12px;
  font-weight: 700;
  letter-spacing: .5px;
  text-transform: uppercase;
}
.ivo-card__body h2 {
  margin: 0 0 12px;
  font-size: 24px;
  font-weight: 600;
  color: var(--ivo-ink);
  line-height: 1.2;
}
.ivo-card__body p {
  margin: 0 0 24px;
  font-size: 14px;
  color: var(--ivo-muted);
  line-height: 1.5;
}
.ivo-card__actions {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 14px;
}
.ivo-card__button {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  background: var(--ivo-purple);
  color: #fff !important;
  font-weight: 600;
  font-size: 14px;
  padding: 10px 18px;
  border-radius: 6px;
  text-decoration: none;
  transition: all .2s;
  border: none;
  cursor: pointer;
}
.ivo-card__button:hover {
  background: #7313b8;
  transform: translateY(-1px);
}
.ivo-card__button-secondary {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  background: #fff;
  color: var(--ivo-ink) !important;
  font-weight: 600;
  font-size: 14px;
  padding: 10px 18px;
  border-radius: 6px;
  text-decoration: none;
  border: 1px solid #d0d5dd;
  transition: all .2s;
  cursor: pointer;
}
.ivo-card__button-secondary:hover {
  background: #f9fafb;
}
.ivo-card__setup {
  flex: 0 1 340px;
  width: 100%;
}
.ivo-card__setup-box {
  background: rgba(255, 255, 255, .6);
  border: 1px solid rgba(255, 255, 255, .8);
  backdrop-filter: blur(8px);
  border-radius: 12px;
  padding: 24px;
  box-shadow: 0 10px 25px rgba(0, 0, 0, .03);
}
.ivo-card__step {
  display: flex;
  gap: 14px;
  margin-bottom: 24px;
  position: relative;
}
.ivo-card__step:last-child {
  margin-bottom: 0;
}
.ivo-card__step::after {
  content: "";
  position: absolute;
  left: 11px;
  top: 28px;
  bottom: -20px;
  width: 2px;
  background: #f3f4f6;
}
.ivo-card__step:last-child::after {
  display: none;
}
.ivo-card__step-number {
  width: 24px;
  height: 24px;
  border-radius: 99px;
  background: var(--ivo-ink);
  color: #fff;
  display: grid;
  place-items: center;
  font-size: 11px;
  font-weight: 700;
  flex-shrink: 0;
  z-index: 2;
}
.ivo-card__step strong {
  display: block;
  font-size: 13px;
  color: var(--ivo-ink);
  margin-bottom: 2px;
}
.ivo-card__step span {
  display: block;
  font-size: 12px;
  color: var(--ivo-muted);
  line-height: 1.4;
}
.ivo-card__tag {
  margin-left: auto;
  align-self: flex-start;
  font-size: 11px !important;
  font-weight: 600 !important;
  padding: 2px 8px !important;
  border-radius: 4px !important;
  background: #f3f4f6 !important;
  color: #4b5563 !important;
}
.ivo-card__tag--active {
  background: #eef2ff !important;
  color: #4f46e5 !important;
}
.ivo-card__tag--success {
  background: #ecfdf5 !important;
  color: #059669 !important;
}
@media (max-width: 800px) {
  .ivo-card__setup { flex: 1 1 100%; }
}
</style>';

        $html .= '<div style="width: 60%; margin: 0 auto;">';
        $html .= '<div class="ivo-card">';
        
        // Header
        $html .= '<div class="ivo-card__head">';
        $html .= '<div class="ivo-card__icon"><i class="icon-cog"></i></div>';
        if ($isConfigured) {
            $html .= '<div class="ivo-card__status ivo-card__status--connected"><div class="ivo-card__status-dot ivo-card__status-dot--connected"></div>' . __('Connected') . '</div>';
        } else {
            $html .= '<div class="ivo-card__status"><div class="ivo-card__status-dot"></div>' . __('Inactive') . '</div>';
        }
        $html .= '</div>';

        // Body
        $html .= '<div class="ivo-card__body">';
        
        // Left Column
        $html .= '<div>';
        $html .= '<div class="ivo-card__logo"><img src="https://static.ivo.md/favicon_white.png" alt="IVO" style="width: 42px; height: 42px; object-fit: contain; display: block;" /></div>';
        $html .= '<p class="ivo-card__eyebrow">' . __('Magento bridge') . '</p>';
        
        if (!$isConfigured || $apiError) {
            $html .= '<h2>' . __('Connect your store') . '</h2>';
            $html .= '<p>' . __('Link your Magento store to IVO Marketplace to start syncing products and receiving orders automatically.') . '</p>';
            
            if ($apiError) {
                $html .= '<div style="background: #fee2e2; border-left: 4px solid #ef4444; color: #991b1b; padding: 12px 16px; margin-bottom: 24px; border-radius: 4px;">';
                $html .= '<strong>' . __('API Error:') . '</strong> ' . htmlspecialchars($apiError);
                $html .= '</div>';
            }
            
            $html .= '<div class="ivo-card__actions">';
            $html .= '<button type="button" class="ivo-card__button" onclick="setLocation(\'' . $urlSetup . '\')">' . __('Configure connection') . '</button>';
            $html .= '</div>';
        } else {
            $html .= '<h2>' . __('Settings') . '</h2>';
            $html .= '<p>' . __('Your store is successfully connected to IVO. Use the native Magento form below to modify advanced settings.') . '</p>';
            
            $html .= '<div class="ivo-card__actions" style="margin-top: 26px;">';
            $html .= '<button type="button" class="ivo-card__button-secondary" onclick="setLocation(\'' . $urlSetup . '\')">' . __('Reconfigure') . '</button>';
            $html .= '<button type="button" id="ivo-force-sync" class="ivo-card__button">' . __('Force Sync') . '</button>';
            $html .= '</div>';
            $html .= '<p id="ivo-sync-status" style="margin: 14px 0 0; font-weight: 600;"></p>';
            $html .= $this->getForceSyncScript($urlSync, $this->_ivoHelper->isResyncRequested());
        }
        $html .= '</div>'; // End Left Column

        // Right Column (Setup box)
        $html .= '<div class="ivo-card__setup">';
        $html .= '<div class="ivo-card__setup-box">';
        
        // Step 1
        $html .= '<div class="ivo-card__step">';
        $html .= '<div class="ivo-card__step-number">1</div>';
        $html .= '<div><strong>' . __('Connect Account') . '</strong><span>' . __('Link your IVO Merchant account.') . '</span></div>';
        if ($isConfigured) {
            $html .= '<div class="ivo-card__tag ivo-card__tag--success">' . __('Active') . '</div>';
        } else {
            $html .= '<div class="ivo-card__tag ivo-card__tag--active">' . __('Required') . '</div>';
        }
        $html .= '</div>';
        
        // Step 2
        $html .= '<div class="ivo-card__step">';
        $html .= '<div class="ivo-card__step-number">2</div>';
        $html .= '<div><strong>' . __('Product sync') . '</strong><span>' . __('Map categories, prices, stock, and images.') . '</span></div>';
        if ($isConfigured) {
            $html .= '<div class="ivo-card__tag ivo-card__tag--success">' . __('Active') . '</div>';
        } else {
            $html .= '<div class="ivo-card__tag">' . __('Next') . '</div>';
        }
        $html .= '</div>';

        // Step 3
        $html .= '<div class="ivo-card__step">';
        $html .= '<div class="ivo-card__step-number">3</div>';
        $html .= '<div><strong>' . __('Update stock') . '</strong><span>' . __('Automatically sync stock between your store and IVO.') . '</span></div>';
        if ($isConfigured && $updateStock) {
            $html .= '<div class="ivo-card__tag ivo-card__tag--success">' . __('Active') . '</div>';
        } else {
            if ($isConfigured) {
                $html .= '<div class="ivo-card__tag">' . __('Inactive') . '</div>';
            } else {
                $html .= '<div class="ivo-card__tag">' . __('Ready') . '</div>';
            }
        }
        $html .= '</div>';
        
        $html .= '</div></div>'; // End Setup Box & Right Column
        
        $html .= '</div></div></div>'; // End Body, Card, Wrapper

        return $html;
    }

    /**
     * Force Sync goes slice by slice: each request sends one batch and says
     * where the next one starts, so no request nears the shop's timeout. A
     * pending re-send (the price modifier changed) starts it on page load.
     */
    private function getForceSyncScript($urlSync, $autoStart)
    {
        $js = function ($value) {
            return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        };
        $labels = [
            'forceSync' => (string)__('Force Sync'),
            'syncing' => (string)__('Syncing...'),
            'progress' => (string)__('Synced %1 of %2 products...'),
            'empty' => (string)__('Empty response from sync.'),
            'failed' => (string)__('Request failed: %1'),
            'resync' => (string)__('The price modifier changed: re-sending all products to IVO.'),
        ];

        return '<script>
require([\'jquery\'], function ($) {
    var syncUrl = ' . $js($urlSync) . ';
    var labels = ' . $js($labels) . ';
    var button = $(\'#ivo-force-sync\');
    var status = $(\'#ivo-sync-status\');
    var running = false;

    function runSync(note) {
        if (running) {
            return;
        }
        running = true;
        button.prop(\'disabled\', true).text(labels.syncing);
        status.css(\'color\', \'\').text(note);

        var params = {after: 0, run_id: \'\', form_key: window.FORM_KEY};
        var retries = 0;

        function finish(text, ok) {
            running = false;
            button.prop(\'disabled\', false).text(labels.forceSync);
            status.css(\'color\', ok ? \'#047857\' : \'#b91c1c\').text(text);
        }

        // Sending a slice again is safe: the same products, the same run
        function retry() {
            if (retries < 2) {
                retries++;
                setTimeout(next, 2000 * retries);
                return true;
            }
            return false;
        }

        function next() {
            $.ajax({
                url: syncUrl,
                type: \'POST\',
                data: params,
                dataType: \'json\',
                success: function (json) {
                    if (!json || typeof json !== \'object\') {
                        finish(labels.empty, false);
                    } else if (json.error) {
                        if (!(json.retry && retry())) {
                            finish(json.error, false);
                        }
                    } else if (typeof json.done === \'undefined\') {
                        finish(labels.empty, false);
                    } else if (json.done) {
                        finish(json.success || \'\', true);
                    } else {
                        retries = 0;
                        params.after = json.after;
                        params.run_id = json.run_id;
                        status.text((note ? note + \' \' : \'\') + labels.progress.replace(\'%1\', json.position).replace(\'%2\', json.total));
                        next();
                    }
                },
                error: function (xhr, textStatus, error) {
                    if (!retry()) {
                        finish(labels.failed.replace(\'%1\', error || (\'HTTP \' + xhr.status)), false);
                    }
                }
            });
        }

        next();
    }

    button.on(\'click\', function () {
        runSync(\'\');
    });
    if (' . ($autoStart ? 'true' : 'false') . ') {
        runSync(labels.resync);
    }
});
</script>';
    }
}
