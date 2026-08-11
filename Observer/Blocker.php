<?php

namespace Hexasoft\IP2LocationCountryBlocker\Observer;

use Magento\Framework\Event\ObserverInterface;

class Blocker implements ObserverInterface
{
	private const SESSION_CACHE_KEY = 'ip2location_country_code';

	/**
	 * @var \Hexasoft\IP2LocationCountryBlocker\Helper\Data
	 */
	protected $helper;

	/**
	 * @var \Magento\Framework\App\Request\Http
	 */
	protected $request;

	/**
	 * @var \Magento\Framework\App\ActionFlag
	 */
	protected $actionFlag;

	/**
	 * @var \Magento\Store\Model\StoreManagerInterface
	 */
	protected $_storeManagerInterface;

	/**
	 * @var \Magento\Framework\App\ResponseFactory
	 */
	private $responseFactory;

	/**
	 * @var \Magento\Framework\UrlInterface
	 */
	private $url;

	/**
	 * @var \Magento\Framework\View\LayoutInterface
	 */
	private $layout;

	/**
	 * @var \Magento\Customer\Model\Session
	 */
	private $customerSession;

	public function __construct(
		\Hexasoft\IP2LocationCountryBlocker\Helper\Data $helper,
		\Magento\Framework\App\RequestInterface $request,
		\Magento\Framework\App\ResponseFactory $responseFactory,
		\Magento\Framework\App\ActionFlag $actionFlag,
		\Magento\Store\Model\StoreManagerInterface $storeManagerInterface,
		\Magento\Framework\UrlInterface $url,
		\Magento\Framework\View\LayoutInterface $layout,
		\Magento\Customer\Model\Session $customerSession
	) {
		$this->helper = $helper;
		$this->request = $request;
		$this->responseFactory = $responseFactory;
		$this->url = $url;
		$this->_storeManagerInterface = $storeManagerInterface;
		$this->actionFlag = $actionFlag;
		$this->layout = $layout;
		$this->customerSession = $customerSession;
	}

	public function execute(\Magento\Framework\Event\Observer $observer)
	{
		if (!$this->helper->isEnabled()) {
			return $this;
		}

		if ($this->request->isAjax()) {
			return $this;
		}

		$ipAddress = $this->helper->getClientIp();

		if ($this->isIpWhitelisted($ipAddress)) {
			return $this;
		}

		if ($this->isIpBlocked($ipAddress)) {
			$this->deny($observer);
			return $this;
		}

		$countries = $this->helper->getCountries();

		if (!$countries || empty($countries)) {
			return $this;
		}

		// Session-cached country lookup
		$cachedValue = $this->helper->isSessionCacheEnabled()
			? $this->customerSession->getData(self::SESSION_CACHE_KEY)
			: null;

		if ($cachedValue !== null) {
			$countryCode = ($cachedValue === '') ? false : $cachedValue;
		} else {
			$countryCode = $this->getCountryCodeByIp($ipAddress);
			if ($this->helper->isSessionCacheEnabled()) {
				$this->customerSession->setData(self::SESSION_CACHE_KEY, $countryCode === false ? '' : $countryCode);
			}
		}

		if ($countryCode && \in_array($countryCode, $countries)) {
			$this->deny($observer);
			return $this;
		}

		if ($this->helper->isRedirectionEnabled() && $countryCode) {
			$storeManagerDataList = $this->_storeManagerInterface->getStores();

			foreach ($storeManagerDataList as $value) {
				if (strtoupper($value->getCode()) === strtoupper($countryCode)) {
					$this->_storeManagerInterface->setCurrentStore($value->getId());
				}
			}
		}

		return $this;
	}

	public function getCountryCodeByIp($ip)
	{
		$apiKey = (string) $this->helper->getApiKey();

		if (preg_match('/^[A-Z0-9]{10}$/i', $apiKey)) {
			$json = json_decode($this->fetch('https://api.ip2location.com/v2/?' . http_build_query([
				'key' => $apiKey,
				'ip'  => $ip,
			])));

			if (isset($json->country_code) && \strlen($json->country_code) == 2) {
				return $json->country_code;
			}
		}

		if (preg_match('/^[A-Z0-9]{32}$/i', $apiKey)) {
			$json = json_decode($this->fetch('https://api.ip2location.io/?' . http_build_query([
				'key' => $apiKey,
				'ip'  => $ip,
			])));

			if (isset($json->country_code) && \strlen($json->country_code) == 2) {
				return $json->country_code;
			}
		}

		if (file_exists($this->helper->getDatabase())) {
			$db = new \IP2Location\Database($this->helper->getDatabase(), \IP2Location\Database::FILE_IO);
			$response = $db->lookup($ip, \IP2Location\Database::ALL);

			if (isset($response['countryCode']) && \strlen($response['countryCode']) == 2) {
				return $response['countryCode'];
			}
		}

		return false;
	}

	public function fetch($url)
	{
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

		$data = curl_exec($ch);

		if (\curl_errno($ch)) {
			curl_close($ch);
			return false;
		}

		curl_close($ch);

		return $data;
	}

	protected function deny(\Magento\Framework\Event\Observer $observer): void
	{
		$this->actionFlag->set('', \Magento\Framework\App\Action\Action::FLAG_NO_DISPATCH, true);
		$response = $observer->getControllerAction()->getResponse();
		$type = $this->helper->getBlockPageType();

		if ($type === 'redirect') {
			$url = $this->helper->getRedirectUrl();
			if ($url) {
				$response->setRedirect($url, 302);
				return;
			}
		}

		if ($type === 'cms_block') {
			$identifier = $this->helper->getCmsBlockIdentifier();
			if ($identifier) {
				$block = $this->layout->createBlock(\Magento\Cms\Block\Block::class)->setBlockId($identifier);
				$html = $block->toHtml();
				if ($html) {
					$response->clearBody()->setStatusCode(200)->setBody($html);
					return;
				}
			}
		}

		$response->clearBody()
			->setStatusCode(\Magento\Framework\App\Response\Http::STATUS_CODE_403)
			->setBody('<html><head><title>403 Forbidden</title></head><body><h1>Forbidden</h1><p>You do not have permission to access this page.</p></body></html>');
	}

	protected function isIpWhitelisted(string $clientIp): bool
	{
		$list = $this->helper->getIpWhitelist();

		foreach ($list as $ip) {
			if ($this->matchesIpEntry($clientIp, $ip)) {
				return true;
			}
		}

		return false;
	}

	protected function isIpBlocked($clientIp)
	{
		$list = $this->helper->getIpBlacklist();

		if ($list) {
			foreach ($list as $ip) {
				if ($this->matchesIpEntry($clientIp, $ip)) {
					return true;
				}
			}
		}

		return false;
	}

	protected function matchesIpEntry(string $clientIp, string $ip): bool
	{
		if ($ip === $clientIp) {
			return true;
		}

		if (strpos($ip, '/') !== false) {
			if ($this->withinCIDR($clientIp, $ip)) {
				return true;
			}
		}

		if (strpos($ip, '*') !== false) {
			$pattern = str_replace(['.', '*'], ['\.', '[0-9]*'], $ip);

			if (preg_match('/^' . trim($pattern) . '$/', $clientIp)) {
				return true;
			}
		}

		return false;
	}

	protected function withinCIDR($ip, $range)
	{
		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			list($subnet, $bits) = explode('/', $range);
			$bits = (int) $bits;
			$ip = ip2long($ip);
			$subnet = ip2long($subnet);
			$mask = -1 << (32 - $bits);
			$subnet &= $mask;

			return ($ip & $mask) == $subnet;
		} elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
			$ip = inet_pton($ip);
			$binary = $this->inetToBits($ip);

			list($subnet, $bits) = explode('/', $range);
			$bits = (int) $bits;
			$subnet = inet_pton($subnet);
			$binarynet = $this->inetToBits($subnet);

			$ipBits = substr($binary, 0, $bits);
			$netBits = substr($binarynet, 0, $bits);

			return $ipBits === $netBits;
		}

		return false;
	}

	protected function inetToBits($inet)
	{
		$unpacked = unpack('A16', $inet);
		$unpacked = str_split($unpacked[1]);
		$binaryip = '';
		foreach ($unpacked as $char) {
			$binaryip .= str_pad(decbin(\ord($char)), 8, '0', STR_PAD_LEFT);
		}

		return $binaryip;
	}
}
