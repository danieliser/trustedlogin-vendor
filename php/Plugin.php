<?php

namespace TrustedLogin\Vendor;

use TrustedLogin\Vendor\Contracts\SendsApiRequests as ApiSend;
use TrustedLogin\Vendor\SettingsApi;
use TrustedLogin\Vendor\TeamSettings;
class Plugin
{
	/**
	 * @var Encryption
	 */
	protected $encryption;

	/**
	 * @var AuditLog
	 */
	protected $auditLog;

	/**
	 * @var ApiSend
	 */
	protected $apiSender;

	/**
	 * @var SettingsApi
	 */
	protected $settings;

	/**
	 * @param Encryption $encryption
	 */
	public function __construct(Encryption $encryption)
	{
		$this->encryption = $encryption;
		$this->auditLog = new AuditLog();
		$this->apiSender = new \TrustedLogin\Vendor\ApiSend();
		$this->settings = SettingsApi::fromSaved();
	}



	/**
	 * Add REST API endpoints
	 *
	 * @uses "rest_api_init" action
	 */
	public function restApiInit()
	{
		(new \TrustedLogin\Vendor\Endpoints\Settings())
			->register(true);
		(new \TrustedLogin\Vendor\Endpoints\PublicKey())
			->register(false);
		(new \TrustedLogin\Vendor\Endpoints\SignatureKey())
			->register(false);
	}

	/**
	 * Get the encryption API
	 *
	 * @return Encryption
	 */
	public function getEncryption()
	{
		return $this->encryption;
	}


	/**
	 * Get the encyption public key
	 *
	 * @return string
	 */
	public function getPublicKey()
	{
		return $this->encryption
			->get_public_key();
	}

	/**
	 * Get the encyption signature key
	 *
	 * @return string
	 */
	public function getSignatureKey()
	{
		return $this->encryption
			->get_public_key('sign_public_key');
	}


	/**
	 * Get API Handler by account id
	 *
	 * @param string $accountId Account ID, which must be saved in settings, to get handler for.
	 * @param string $apiUrl Optional. Url for TrustedLogin API.
	 * @param null|TeamSettings $team Optional. TeamSettings  to use.
	 */
	public function getApiHandler($accountId, $apiUrl = '', $team = null )
	{
		if( ! $team ) {
			$team = SettingsApi::fromSaved()->getByAccountId($accountId);
		}
		if (empty($apiUrl)) {
			$apiUrl = TRUSTEDLOGIN_API_URL;
		}
		return new ApiHandler([
			'private_key' => $team->get('private_key'),
			'api_key'  => $team->get('api_key'),
			'debug_mode'  => $team->get('debug_enabled'),
			'type'        => 'saas',
			'api_url' => $apiUrl
		], $this->apiSender );
	}

	public function getApiUrl()
	{
		return apply_filters('trustedlogin/api-url/saas', TRUSTEDLOGIN_API_URL);
	}

	/**
	 * @return \TrustedLogin\Vendor\AuditLog
	 */
	public function getAuditLog()
	{
		return $this->auditLog;
	}

	/**
	 * Set the apiSender instance
	 *
	 * @param ApiSend $apiSender
	 * @return $this
	 */
	public function setApiSender(ApiSend $apiSender)
	{
		$this->apiSender = $apiSender;
		return $this;
	}

	public function getAccessKeyActions(){
		$data = [];
		foreach($this->settings->allTeams(false) as $team){
			$url = AccessKeyLogin::url(
				$team->get('account_id'),
				'helpscout',
				//$team->get_helpdesks()[0]
			);
			$data[$team->get('account_id')] = $url;
		}
		return $data;
	}
}
