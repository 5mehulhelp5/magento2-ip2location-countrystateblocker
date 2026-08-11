<?php

namespace Hexasoft\IP2LocationCountryBlocker\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Store\Model\ScopeInterface;

class Data extends AbstractHelper
{
	public const XML_PATH_ENABLED = 'country_blocker/settings/enable';
	public const XML_PATH_ENABLED_REDIRECTION = 'country_blocker/redirection/enable_redirection';
	public const XML_PATH_COUNTRIES = 'country_blocker/settings/countries';
	public const XML_PATH_IP_BLACKLIST = 'country_blocker/settings/ip_blacklist';
	public const XML_PATH_DATABASE = 'country_blocker/settings/database';
	public const XML_PATH_API_KEY = 'country_blocker/settings/api_key';
	public const XML_PATH_IP_WHITELIST = 'country_blocker/settings/ip_whitelist';
	public const XML_PATH_ENABLE_SESSION_CACHE = 'country_blocker/settings/enable_session_cache';
	public const XML_PATH_BLOCK_PAGE_TYPE = 'country_blocker/block_page/block_page_type';
	public const XML_PATH_CMS_BLOCK_IDENTIFIER = 'country_blocker/block_page/cms_block_identifier';
	public const XML_PATH_REDIRECT_URL = 'country_blocker/block_page/redirect_url';
	public const IP_LIST_REGEXP_DELIMITER = '/[\r?\n]+/';

	private $remoteAddress;

	public function __construct(Context $context, RemoteAddress $remoteAddress)
	{
		parent::__construct($context);
		$this->remoteAddress = $remoteAddress;
	}

	/**
	 * @param null $storeId
	 *
	 * @return bool
	 */
	public function isEnabled($storeId = null)
	{
		return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
	}

	/**
	 * @param null $storeId
	 *
	 * @return bool
	 */
	public function isRedirectionEnabled($storeId = null)
	{
		return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED_REDIRECTION, ScopeInterface::SCOPE_STORE, $storeId);
	}

	/**
	 * @param null $storeId
	 *
	 * @return array|null
	 */
	public function getCountries($storeId = null)
	{
		$countriesRawValue = $this->scopeConfig->getValue(self::XML_PATH_COUNTRIES, ScopeInterface::SCOPE_STORE, $storeId);

		$countriesRawValue = $this->prepareCode($countriesRawValue);

		if ($countriesRawValue) {
			$countriesCode = explode(',', $countriesRawValue);

			return $countriesCode;
		}

		return $countriesRawValue ? $countriesRawValue : null;
	}

	/**
	 * @param null $storeId
	 *
	 * @return array
	 */
	public function getIpBlacklist($storeId = null)
	{
		$rawIpList = $this->scopeConfig->getValue(self::XML_PATH_IP_BLACKLIST, ScopeInterface::SCOPE_STORE, $storeId);

		$ipList = array_filter((array) preg_split(self::IP_LIST_REGEXP_DELIMITER, (string) $rawIpList));

		return $ipList;
	}

	/**
	 * Changes country code to upper case.
	 *
	 * @param string $countryCode
	 *
	 * @return string
	 */
	public function prepareCode($countryCode)
	{
		return strtoupper(trim($countryCode));
	}

	/**
	 * Get Database From Config.
	 *
	 * @param mixed|null $storeId
	 *
	 * @return string
	 */
	public function getDatabase($storeId = null)
	{
		return (string) $this->scopeConfig->getValue(self::XML_PATH_DATABASE, ScopeInterface::SCOPE_STORE, $storeId);
	}

	/**
	 * Get API Key From Config.
	 *
	 * @param mixed|null $storeId
	 *
	 * @return string
	 */
	public function getApiKey($storeId = null)
	{
		return $this->scopeConfig->getValue(self::XML_PATH_API_KEY, ScopeInterface::SCOPE_STORE, $storeId);
	}

	public function getIpWhitelist($storeId = null): array
	{
		$raw = $this->scopeConfig->getValue(self::XML_PATH_IP_WHITELIST, ScopeInterface::SCOPE_STORE, $storeId);
		return array_filter((array) preg_split(self::IP_LIST_REGEXP_DELIMITER, (string) $raw));
	}

	public function isSessionCacheEnabled($storeId = null): bool
	{
		return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLE_SESSION_CACHE, ScopeInterface::SCOPE_STORE, $storeId);
	}

	public function getBlockPageType($storeId = null): string
	{
		return (string) $this->scopeConfig->getValue(self::XML_PATH_BLOCK_PAGE_TYPE, ScopeInterface::SCOPE_STORE, $storeId) ?: 'default';
	}

	public function getCmsBlockIdentifier($storeId = null): string
	{
		return (string) $this->scopeConfig->getValue(self::XML_PATH_CMS_BLOCK_IDENTIFIER, ScopeInterface::SCOPE_STORE, $storeId);
	}

	public function getRedirectUrl($storeId = null): string
	{
		return (string) $this->scopeConfig->getValue(self::XML_PATH_REDIRECT_URL, ScopeInterface::SCOPE_STORE, $storeId);
	}

	public function getClientIp()
	{
		// If website is hosted behind CloudFlare protection.
		if (isset($_SERVER['HTTP_CF_CONNECTING_IP']) && filter_var($_SERVER['HTTP_CF_CONNECTING_IP'], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
			return $_SERVER['HTTP_CF_CONNECTING_IP'];
		}

		if (isset($_SERVER['HTTP_X_REAL_IP']) && filter_var($_SERVER['HTTP_X_REAL_IP'], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
			return $_SERVER['HTTP_X_REAL_IP'];
		}

		if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			// Get server IP address
			$serverIp = (isset($_SERVER['SERVER_ADDR'])) ? $_SERVER['SERVER_ADDR'] : '';
			$ip = trim(current(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])));

			if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) && $ip != $serverIp) {
				return $ip;
			}
		}

		return trim($this->remoteAddress->getRemoteAddress());
	}
}
