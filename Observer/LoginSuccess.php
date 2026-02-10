<?php

/**
 * Copyright © Webscale. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Webscale\EventStream\Observer;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\PhpEnvironment\Request;
use Magento\Framework\Logger\Monolog;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Throwable;
use function gmdate;
use function json_encode;
use function rtrim;
use function sprintf;
use function trim;
use const CURLOPT_CONNECTTIMEOUT;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_TIMEOUT;

class LoginSuccess implements ObserverInterface
{
    /** Config paths */
    private const XML_PATH_ENABLED = 'webscale_eventstream/general/enabled';

    private const XML_PATH_LOGGING = 'webscale_eventstream/developer/logging';

    private const ENDPOINT_PATH = '/.clickstream/events/batch';

    private const MODULE_NAME = 'Webscale_EventStream';

    private const MODULE_PLATFORM = 'magento';

    private const MODULE_EVENT_LOGIN = 'login';

    private const COOKIE_ID = 'wbs_uid';

    private ScopeConfigInterface $scopeConfig;

    private Curl $curl;

    private StoreManagerInterface $storeManager;

    private Monolog $logger;

    private Request $request;

    private CookieManagerInterface $cookieManager;

    private ModuleListInterface $moduleList;

    private $logging = null;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        Curl $curl,
        StoreManagerInterface $storeManager,
        Monolog $logger,
        Request $request,
        CookieManagerInterface $cookieManager,
        ModuleListInterface $moduleList
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->curl = $curl;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
        $this->request = $request;
        $this->cookieManager = $cookieManager;
        $this->moduleList = $moduleList;
    }

    public function execute(Observer $observer): void
    {
        try {
            if (!$this->isEnabled()) {
                return;
            }

            $customer = $observer->getEvent()->getCustomer();
            if (!$customer) {
                if ($this->getLogging()) {
                    $this->logger->info('[Webscale_EventStream] No customer on customer_login event');
                }

                return;
            }

            // endpoint is a dynamic self base url + static path
            $endpoint = $this->getEndpoint();

            // retrieve webscale app id
            $appId = $this->request->getHeader('Webscale-App-Id');
            $payload = $this->getPayload($customer);

            // limit connection time for request
            $this->curl->setOptions(
                [
                    CURLOPT_TIMEOUT => 5,
                    CURLOPT_CONNECTTIMEOUT => 1,
                    CURLOPT_RETURNTRANSFER => true
                ]
            );

            // set request headers
            $this->curl->setHeaders(
                [
                    'Content-Type' => 'application/json',
                    'Webscale-App-Id' => $appId ?? ''
                ]
            );

            // execute request
            $this->curl->post($endpoint, json_encode($payload));

            // get response data
            $status = $this->curl->getStatus();
            if ($status >= 200 && $status < 300) {
                if ($this->getLogging()) {
                    $this->logger->info(
                        '[Webscale_EventStream] Login payload sent',
                        [
                            'endpoint' => $endpoint,
                            'payload' => $payload,
                            'response' => $this->curl->getBody(),
                        ]
                    );
                }
            } else {
                $body = $this->curl->getBody();
                $isJson = str_starts_with(trim($body), '{') || str_starts_with(trim($body), '[');

                $this->logger->warning(
                    sprintf('[Webscale_EventStream] HTTP %d while sending login payload', $status),
                    [
                        'endpoint' => $endpoint,
                        'payload' => $payload,
                        'response' => $isJson ? $body : '[non-JSON response omitted]',
                    ]
                );
            }
        } catch (Throwable $e) {
            // never block login flow
            $this->logger->error(
                '[Webscale_EventStream] Exception while sending login payload',
                [
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]
            );
        }
    }

    /**
     * Compiles payload
     *
     * @throws LocalizedException|NoSuchEntityException
     */
    private function getPayload(CustomerInterface|\Magento\Customer\Model\Customer $customer): array
    {
        return [
            [
                'platform' => self::MODULE_PLATFORM,
                'sdk' => 'webscale/eventstream:' . $this->getModuleVersion(self::MODULE_NAME),
                'event_name' => self::MODULE_EVENT_LOGIN,
                'event_id' => $this->generateUuidV4(),
                'timestamp' => gmdate('c'),
                'user' => [
                    'user_id' => (string) $customer->getId(),
                    'magento' => [
                        'store_id' => $this->storeManager->getStore()->getCode(),
                        'website_id' => $this->storeManager->getWebsite()->getCode(),
                    ],
                ],
                'payload' => [
                    'email' => (string) $customer->getEmail(),
                    self::COOKIE_ID => $this->cookieManager->getCookie(self::COOKIE_ID)
                ]
            ]
        ];
    }

    private function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_WEBSITE);
    }

    private function getLogging(): bool
    {
        if ($this->logging === null) {
            $this->logging = $this->scopeConfig->isSetFlag(self::XML_PATH_LOGGING, ScopeInterface::SCOPE_WEBSITE);
        }

        return $this->logging;
    }

    /**
     * Endpoint: base_url (taking current store into consideration) + endpoint_path
     *
     * @throws NoSuchEntityException
     */
    private function getEndpoint(): string
    {
        $store = $this->storeManager->getStore();

        // base URL per store + path
        return rtrim($store->getBaseUrl(UrlInterface::URL_TYPE_WEB), '/') . self::ENDPOINT_PATH;
    }

    /**
     * Generates random UUID string
     */
    private function generateUuidV4(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    /**
     * Retrieve extension version
     */
    private function getModuleVersion(string $moduleName): ?string
    {
        $module = $this->moduleList->getOne($moduleName);

        return $module['setup_version'] ?? null;
    }
}
