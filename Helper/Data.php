<?php

namespace Zaius\Engage\Helper;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Filter\Encrypt;
use Magento\Framework\Registry;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Quote\Model\Quote;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\SalesRule\Model\RuleRepository;
use Zaius\Engage\Model\Client;
use Zaius\Engage\Model\Session;
use Zaius\Engage\Helper\Locale as LocaleHelper;
use Zaius\Engage\Logger\Logger;

class Data
    extends AbstractHelper
{
    const MODULE_NAME = 'Zaius_Engage';
    const API_URL = 'http://api.zaius.com/v2';
    const VUID_LENGTH = 36;

    const EVENTS_REGISTRY_KEY = 'zaius_current_events';

    protected $_cookieManager;
    protected $_categoryRepository;
    protected $_encryptor;
    protected $_session;
    protected $_registry;
    protected $_moduleList;
    protected $_ruleRepository;
    protected $_localeHelper;
    protected $_sdk;
    protected $_logger;

    public function __construct(
        CookieManagerInterface $cookieManager,
        CategoryRepositoryInterface $categoryRepository,
        EncryptorInterface $encryptor,
        Session $session,
        Registry $registry,
        ModuleListInterface $moduleList,
        RuleRepository $ruleRepository,
        Context $context,
        Sdk $sdk,
        LocaleHelper $localeHelper,
        Logger $logger
    )
    {
        $this->_cookieManager = $cookieManager;
        $this->_categoryRepository = $categoryRepository;
        $this->_encryptor = $encryptor;
        $this->_session = $session;
        $this->_registry = $registry;
        $this->_moduleList = $moduleList;
        $this->_ruleRepository = $ruleRepository;
        $this->_localeHelper = $localeHelper;
        $this->_sdk = $sdk;
        $this->_logger = $logger;
        parent::__construct($context);
    }

    public function getVersion()
    {
        return $this->_moduleList->getOne(self::MODULE_NAME)['setup_version'];
    }

    /**
     * @param \Magento\Store\Model\Store|int|null $store
     * @return bool
     */
    public function getStatus($store = null)
    {
        return $this->scopeConfig->isSetFlag('zaius_engage/status/status', 'store', $store);
    }

    /**
     * @return string
     */
    public function getApiBaseUrl()
    {
        return self::API_URL;
    }

    /**
     * @param \Magento\Store\Model\Store|int|null $store
     * @return string
     */
    public function getZaiusTrackerId($store = null)
    {
        return $this->scopeConfig->getValue('zaius_engage/config/zaius_tracker_id', 'store', $store);
    }

    /**
     * @param \Magento\Store\Model\Store|int|null $store
     * @return bool
     */
    public function getZaiusPrivateKey($store = null)
    {
        return $this->scopeConfig->getValue('zaius_engage/config/zaius_private_api', 'store', $store);
    }

    /**
     * @param \Magento\Store\Model\Store|int|null $store
     * @return bool
     */
    public function getAmazonS3Status($store = null)
    {
        return $this->scopeConfig->isSetFlag('zaius_engage/config/amazon_active', 'store', $store);
    }

    /**
     * @param \Magento\Store\Model\Store|int|null $store
     * @return bool
     */
    public function getAmazonS3Key($store = null)
    {
        return $this->scopeConfig->getValue('zaius_engage/config/amazon_s3_key', 'store', $store);
    }

    /**
     * @param \Magento\Store\Model\Store|int|null $store
     * @return bool
     */
    public function getAmazonS3Secret($store = null)
    {
        return $this->scopeConfig->getValue('zaius_engage/config/amazon_s3_secret', 'store', $store);
    }

    /**
     * '@param \Magento\Store\Model\Store|int|null $store
     * @return bool
     */
    public function getIsCollectAllProductAttributes($store = null)
    {
        return $this->scopeConfig->isSetFlag('zaius_engage/settings/is_collect_all_product_attributes', 'store', $store);
    }

    /**
     * @param \Magento\Store\Model\Store|int|null $store
     * @return bool
     */
    public function getIsTrackingOrdersOnFrontend($store = null)
    {
        return $this->scopeConfig->isSetFlag('zaius_engage/settings/is_tracking_orders_on_frontend', 'store', $store);
    }

    /**
     * @param \Magento\Store\Model\Store|int|null $store
     * @return int
     */
    public function getTimeout($store = null)
    {
        return intval($this->scopeConfig->getValue('zaius_engage/settings/timeout', 'store', $store));
    }

    /**
     * @param $quote
     * @return bool
     */
    public function isValidCart($quote)
    {
        if ($quote == null || $quote->getId() == null || $quote->getCreatedAt() == null || $quote->getStoreId() == null) {
            return false;
        }
        if ($quote->getItemsCount()){
            return true;
        }
        return false;
    }

    public function prepareCartJSON($quote, $info)
    {
        if ($quote->getItemsCount() == 0) {
            return array('items' => []);
        }
        $items = $quote->getAllVisibleItems();
        $json = array();
        foreach ($items as $item) {
            $pid = $this->getProductId($item);
            $qty = $info[$item->getId()]['qty'] ?? $info;
            $data = [
                'product_id' => $pid,
                'quantity' => $qty
            ];
            $json['items'][] = $data;
        }
        // only add details if a cart has applied rules
        // slating for later
//        if ($quote->getAppliedRuleIds()) {
//            $this->_logger->info("A Product Has A Price Rule: " . $quote->getAppliedRuleIds());
//            foreach ($quote->getAppliedRuleIds() as $ruleId){
//                $rule = $this->_ruleRepository->getById($ruleId);
//                $ruleDescription = $rule->getDescription();
//            }
//            $json['details']['valid_until'] = time();
//            $json['details']['currency_symbol'] = '0';
//            $json['details']['original_value'] = $quote->getGrandTotal();
//            $json['details']['discounted_value'] = $quote->getSubtotalWithDiscount();
//            $json['details']['discount'] = '0';
//            $json['details']['discount_percent'] = '0';
//        }
        return $json;
    }

    public function prepareZaiusCartUrl($baseUrl)
    {
        return $baseUrl . 'zaius/hook/create/client_id/' . $this->getZaiusTrackerId() . '/zaius_cart/';
    }

    public function prepareZaiusCart($quote, $info)
    {
        if ($quote == null || $quote->getId() == null || $quote->getCreatedAt() == null || $quote->getStoreId() == null) {
            return false;
        }
        $items = $quote->getAllVisibleItems();
        $this->_logger->info('Logging zaiusCart: ' . json_encode($quote->getItemsCount()));
        $zaiusCart = array();
        foreach ($items as $item) {
            $pid = $this->getProductId($item);
            $qty = $item->getQty();
            $data = $pid .':'.$qty;
            $zaiusCart[] = $data;
        }
        $this->_logger->info('Logging zaiusCart: ' . json_encode($zaiusCart));
        //todo determine website/store view, return link?
        return implode(',',$zaiusCart);
    }

    /**
     * @param Quote $quote
     * @return string|null
     */
    public function encryptQuote($quote)
    {
        if ($quote == null || $quote->getId() == null || $quote->getCreatedAt() == null || $quote->getStoreId() == null) {
            return null;
        } else {
            return base64_encode($this->_encryptor->encrypt($quote->getId() . $quote->getCreatedAt() . $quote->getStoreId()));
        }
    }

    /**
     * @param string $quoteHash
     * @return string
     */
    public function decryptQuote($quoteHash)
    {
        return $this->_encryptor->decrypt(base64_decode($quoteHash));
    }

    /**
     * @return null|string
     */
    public function getVuid()
    {
        $vuidCookie = $this->_cookieManager->getCookie('vuid');
        $vuid = null;
        if ($vuidCookie && strlen($vuidCookie) >= self::VUID_LENGTH) {
            $vuid = substr($vuidCookie, 0, self::VUID_LENGTH);
        }
        return $vuid;
    }

    /**
     * @return null|array
     */
    public function getVTSRC()
    {
        $vtsrcCookie = $this->_cookieManager->getCookie('vtsrc');
        $vtsrc = null;
        if ($vtsrcCookie) {
            $vtsrc = $this->prepareVtsrc($vtsrcCookie);
        }
        return $vtsrc;
    }

    public function prepareVtsrc($vtsrc)
    {
        $explode = explode('|', urldecode($vtsrc));
        foreach ($explode as $e) {
            list($k, $v) = explode('=', $e);
            $result[$k] = $v;
        }
        return $result;
    }

    public function getZM64_ID()
    {
        $zm64Cookie = $this->_cookieManager->getCookie('zm64_id');
        if ($zm64Cookie) {
            return $zm64Cookie;
        }
        return false;
    }

    public function getZaiusAliasCookies()
    {
        $substr = 'zaius_alias_';
        $zaiusAliasCookies = array();
        foreach ($_COOKIE as $key => $value) {
            // if (substr($key, 0, strlen($substr)) === $substr) {}
            if (strpos($key,$substr) !== false) {
                $cookie = $this->_cookieManager->getCookie($key);
                $zaiusAliasCookies[] = $cookie;
            }
        }
        if (count($zaiusAliasCookies)) {
            return $zaiusAliasCookies;
        }
        return false;
    }

    /**
     * @param \Magento\Catalog\Model\Product $product
     * @return null|string
     * @throws NoSuchEntityException
     */
    public function getCurrentOrDeepestCategoryAsString($product)
    {
        /** @var Category $category */
        $category = $this->_registry->registry('current_category');
        if ($category) {
            $bestCategory = $category;
        } else {
            $bestCategory = $maxLevel = null;
            foreach ($product->getCategoryIds() as $categoryId) {
                try {
                    /** @var Category $category */
                    $category = $this->_categoryRepository->get($categoryId);
                    if (!isset($maxLevel) || $maxLevel < $category->getLevel()) {
                        $bestCategory = $category;
                        $maxLevel = $category->getLevel();
                    }
                } catch (NoSuchEntityException $e) {
                    // if category is not found, do nothing
                }
            }
        }
        return isset($bestCategory) ? $this->getCategoryNamePathAsString($bestCategory) : '';
    }

    /**
     * @param \Magento\Catalog\Model\Category $category
     * @param string $separator
     * @return string
     * @throws NoSuchEntityException
     */
    public function getCategoryNamePathAsString($category, $separator = ' > ')
    {
        // ignore root category
        $path = array_slice(explode('/', $category->getPath()), 1);
        $categoryNames = [];
        foreach ($path as $categoryId) {
            /** @var \Magento\Catalog\Model\Category $category */
            $category = $this->_categoryRepository->get($categoryId);
            $categoryNames[] = $category->getName();
        }
        return implode($separator, $categoryNames);
    }

    protected function isBatchUpdate()
    {
        return $this->scopeConfig->getValue(Client::XML_PATH_BATCH_ENABLED);
    }

    public function addEventToRegistry($event) {
        $events = $this->_registry->registry(self::EVENTS_REGISTRY_KEY);
        if(!$events) {
            $events = [$event];
        }
        else {
            $events[]=$event;
            $this->_registry->unregister(self::EVENTS_REGISTRY_KEY);
        }
        $this->_registry->register(self::EVENTS_REGISTRY_KEY,$events);
    }

    /**
     * @param mixed $event
     * @return $this;
     * @throws \ZaiusSDK\ZaiusException
     */
    public function addEventToSession($event)
    {
        if ($this->isBatchUpdate()) {
            $zaiusClient = $this->_sdk->getSdkClient();
            $event = Client::transformForBatchEvent($event);
            if (isset($event['identifiers'])) {
                $vuid = $this->getVuid();
                $zm64_id = $this->getZM64_ID();
                $zaiusAliasCookies = $this->getZaiusAliasCookies();
                $event['identifiers'] = array_filter(compact('vuid', 'zm64_id'));
                if (count($zaiusAliasCookies)) {
                    foreach ($zaiusAliasCookies as $field => $value) {
                        $event['identifiers'][$field] = $value;
                    }
                }
            }
            $zaiusClient->postEvent($event, true);
        } else {
            $events = $this->_session->getEvents();
            if (!$events) {
                $events = [];
            }
            $events[] = $event;
            $this->_session->setEvents($events);
            $this->_session->setCacheBuster(time());
        }

    }

    /**
     * Gets the product id. Since this method can receive products, quote items or order items, we need to pull the id from the right location.
     * Then, if the locale feature is enabled, we add the delimiter and the locale code
     *
     * @param $product
     * @return int|string|null
     */
    public function getProductId($product)
    {
        $productId = null;

        if ($product instanceof Product) {
            $productId = $product->getId();
        }
        if ($product instanceof \Magento\Sales\Model\Order\Item) {
            $productId = $product->getProductId();
        }
        if ($product instanceof \Magento\Quote\Model\Quote\Item) {
            $productId = $product->getProductId();
        }

        if ($this->_localeHelper->isLocalesEnabled()) {
            $productId .= $this->_localeHelper->getLocaleDelimiter() . $this->_localeHelper->getLangCode($product->getStoreId());
        }

        return $productId;
    }
}