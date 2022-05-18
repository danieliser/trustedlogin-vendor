<?php
namespace TrustedLogin\Vendor;

use TrustedLogin\Vendor\Traits\VerifyUser;
use TrustedLogin\Vendor\Traits\Logger;
use TrustedLogin\Vendor\Webhooks\Factory;
/**
 * Handler for access key login
 */
class AccessKeyLogin
{

	use Logger,VerifyUser;

	/**
	 * WordPress admin slug for access key login
	 */
	const PAGE_SLUG = 'trustedlogin_access_key';

	/**
	 * Name of form nonce
	 */
	const NONCE_NAME = '_tl_ak_nonce';

	/**
	 * Name of form nonce action
	 */
	const NONCE_ACTION = 'ak-redirect';

	/**
	 * Query param for redirect URL, to indicate it is a key login
	 */
	const ACCESS_KEY_ACTION_NAME = 'tl_access_key_login';

	/**
	 * Query param for redirect URL, to indicate account ID
	 */
	const ACCOUNT_ID_INPUT_NAME = 'ak_account_id';

	const ACCESS_KEY_INPUT_NAME = 'ak';

	const REDIRECT_ENDPOINT = 'trustedlogin';

	const ERROR_NO_ACCOUNT_ID = 404;

	const ERROR_INVALID_ROLE = 403;

	/*
	* Error for no secret ids founc
	* @See https://developer.mozilla.org/en-US/docs/Web/HTTP/Status/406
	*/
	const ERROR_NO_SECRET_IDS_FOUND = 406;
	/**
	 * Error code for no envelope found
	 *
	 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Status/510
	 */
	const ERROR_NO_ENVELOPE= 510;

	/**
	 * The URL for access key login
	 * @param string $account_id The account ID
	 * @param string $provider The provider name
	 * @param string $access_key (Optional) The key for the access being requested.
	 * @return string
	 */
	public static function url($account_id,$provider,$access_key = ''){
		return Factory::actionUrl(
			self::ACCESS_KEY_ACTION_NAME,
			$account_id,
			$provider,
			$access_key
		);
	}

	public static function makeSecret(){
		return bin2hex(random_bytes(8));
	}
	/**
	 * Processes the request.
	 *
	 * @param array $args. Optional.
	 *
	 * return array|WP_Error
	 */
	public function handle(array $args = [])
	{	//If needed inputs, passed, used those.
		if( isset( $args[self::ACCESS_KEY_INPUT_NAME] ) && isset($args[self::ACCOUNT_ID_INPUT_NAME]) ){
			$access_key = $args[self::ACCESS_KEY_INPUT_NAME];
			$account_id = $args[self::ACCOUNT_ID_INPUT_NAME];
		}
		//If not, use $_REQUEST
		else{
			$verified = $this->verifyGrantAccessRequest();

			if ( is_wp_error($verified)) {
				return $verified;
			}

			$access_key = sanitize_text_field($_REQUEST[ self::ACCESS_KEY_INPUT_NAME ]);
			$account_id = sanitize_text_field($_REQUEST[ self::ACCOUNT_ID_INPUT_NAME]);
		}

		//Get saved settings an then team settings
		$settings = SettingsApi::fromSaved();

		try {
			$teamSettings =  $settings->getByAccountId($account_id);
		} catch (\Exception $e) {
			return new \WP_Error(
				self::ERROR_NO_ACCOUNT_ID,
				'invalid_account_id',
				$e->getMessage()
			);
		}
		if ($this->verifyUserRole($teamSettings)) {
			/** YOLO!
			return new \WP_Error(
				self::ERROR_INVALID_ROLE,
				'invalid_user_role',
				'User does not have the correct role'
			);
			*/
		}

		$tl = new TrustedLoginService(
			trustedlogin_vendor()
		);

		$site_ids = $tl->apiGetSecretIds($access_key, $account_id);

		if (is_wp_error($site_ids)) {
			return new \WP_Error(
				400,
				'invalid_secret_keys',
				$site_ids->get_error_message()
			);
		}

		if (empty($site_ids)) {
			return new \WP_Error(
				self::ERROR_NO_SECRET_IDS_FOUND,
				'no_secret_keys',
				'No secret keys found'
			);
		}

		foreach ($site_ids as $site_id) {
			$envelope = $tl->apiGetEnvelope($site_id, $account_id);
			//Not an error?
			if( ! is_wp_error($envelope)){
				//Break, we got one.
				break;
			}
		}

		//Return the last error, if its an error
		//@todo what about the other ones?
		if (is_wp_error($envelope)) {
			return $envelope;
		}
		//Try to get parts of the envelope,may return WP_Error
		$parts = $tl->envelopeToUrl($envelope, true);
		return $parts;
	}

	/**
	 * Verifies the $_POST request by the Access Key login form.
	 *
	 * @return bool|WP_Error
	 */
	public function verifyGrantAccessRequest()
	{

		if (empty($_REQUEST[ self::ACCESS_KEY_INPUT_NAME ])) {
			$this->log('No access key sent.', __METHOD__, 'error');
			return new \WP_Error('no_access_key', esc_html__('No access key was sent with the request.', 'trustedlogin-vendor'));
		}

		if (empty($_REQUEST[self::ACCOUNT_ID_INPUT_NAME ])) {
			$this->log('No account id  sent.', __METHOD__, 'error');
			return new \WP_Error('no_account_id', esc_html__('No account id was sent with the request.', 'trustedlogin-vendor'));
		}

		if (empty($_REQUEST[ self::NONCE_NAME ])) {
			$this->log('No nonce set. Insecure request.', __METHOD__, 'error');
			return new \WP_Error('no_nonce', esc_html__('No nonce was sent with the request.', 'trustedlogin-vendor'));
		}

		// Valid nonce?
		$valid = wp_verify_nonce($_REQUEST[ self::NONCE_NAME ], self::NONCE_ACTION);

		if (! $valid) {
			$this->log('Nonce is invalid; could be insecure request. Refresh the page and try again.', __METHOD__, 'error');
			return new \WP_Error('bad_nonce', esc_html__('The nonce was not set for the request.', 'trustedlogin-vendor'));

		}

		//Ok, it's chill.
		return true;
	}
}
