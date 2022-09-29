<?php
namespace TrustedLogin\Vendor;

use TrustedLogin\Vendor\Traits\Logger;
use TrustedLogin\Vendor\Plugin;
use TrustedLogin\Vendor\Traits\VerifyUser;

/**
 * High-level API for SaaS interactions
 *
 * Methods include validation, logging and API calls
 */
class TrustedLoginService
{

	use Logger, VerifyUser;

	//Constants came fromhttps://github.com/trustedlogin/trustedlogin-vendor/blob/f8c451d6648a6aa4e1844c4df0952c1bdce87985/includes/class-trustedlogin-endpoint.php#L31-L41
	//Not all are needed.

	const HEALTH_CHECK_SUCCESS_STATUS = 204;

	const HEALTH_CHECK_ERROR_STATUS = 424;

	const PUBLIC_KEY_SUCCESS_STATUS = 200;

	const PUBLIC_KEY_ERROR_STATUS = 501;

	const REDIRECT_SUCCESS_STATUS = 302;

	const REDIRECT_ERROR_STATUS = 303;


	/**
	 * @var Plugin
	 */
	protected $plugin;
	public function __construct(Plugin $plugin)
	{
		$this->plugin = $plugin;
	}

	/**
	 * Helper: Handles the case where a single accessKey returns more than 1 secretId.
	 *
	 * @param string $account_id
	 * @param array $secret_ids [
	 *   @type string $siteurl The url of the site the secretId is for.
	 *   @type string $loginurl The vendor-side redirect link to login via secretId.
	 * ]
	 *
	 * @return void.
	 */
	public function handleMultipleSecretIds($account_id, $secret_ids = array())
	{
		if (! is_array($secret_ids) || empty($secret_ids)) {
			return;
		}

		$valid_secrets = $this->getValidSecrets( $secret_ids, $account_id );

		if (1 === sizeof($valid_secrets)) {
			reset( $valid_secrets );
			$this->maybeRedirectSupport( $valid_secrets[0]['id'], $valid_secrets[0]['envelope'] );
		}

		$urls_output  = '';
		$url_template = '<li><a href="%1$s" class="%2$s">%3$s</a></li>';

		foreach ( $valid_secrets as $valid_secret ) {
			$urls_output .= sprintf(
				$url_template,
				esc_url($valid_secret['url_parts']['loginurl']),
				esc_attr('trustedlogin-authlink'),
				sprintf(esc_html__('Log in to %s', 'trustedlogin-vendor'), esc_html($url_parts['siteurl']))
			);
		}

		if (empty($urls_output)) {
			return;
		}

		add_action('admin_notices', function () use ($urls_output) {
			echo '<div class="notice notice-warning"><h3>' . esc_html__('Choose a site to log into:', 'trustedlogin-vendor') . '</h3><ul>' . $urls_output . '</ul></div>';
		});
	}

	/**
	 * Ingests an array of secret IDs and returns an array of only valid IDs with extra data.
	 *
	 * @since 0.12.0
	 *
	 * @param array $secret_ids
	 * @param $account_id
	 *
	 * @return array{ id:string, url_parts:array, envelope:array }
	 */
	public function getValidSecrets( array $secret_ids, $account_id ) {

		$valid_ids = [];

		foreach ($secret_ids as $secret_id) {

			$envelope = $this->apiGetEnvelope( $secret_id, $account_id );

			$envelope = $this->verifyEnvelope( $envelope );

			if ( is_wp_error( $envelope ) ) {
				$this->log( 'Error: ' . $envelope->get_error_message(), __METHOD__, 'error' );
				continue;
			}

			$this->log( '$envelope is not an error. Here\'s the envelope: ', __METHOD__, 'debug', [
				'envelope' => $envelope,
			] );

			// TODO: Convert to shared (client/vendor) Envelope library
			$url_parts = $this->envelopeToUrl( $envelope, true );

			if ( is_wp_error( $url_parts ) ) {
				$this->log( 'Error: ', __METHOD__, 'error', [
					'error_messages' => $url_parts->get_error_message()
				] );
				continue;
			}

			if ( empty( $url_parts ) ) {
				continue;
			}

			$valid_ids[] = array(
				'id'        => $secret_id,
				'url_parts' => $url_parts,
				'envelope'  => $envelope,
			);
		}

		return $valid_ids;
	}


	/**
	 * Helper: If all checks pass, redirect support agent to client site's admin panel
	 *
	 * @since 0.4.0
	 * @since 0.8.0 Added `Encryption->decrypt()` to decrypt envelope from Vault.
	 *
	 * @see endpoint_maybe_redirect()
	 *
	 * @param string $secret_id collected via endpoint
	 * @param string $account_id collected via endpoint
	 * @param array|\WP_Error Envelope, if already fetched. Optional.
	 *
	 * @return null
	 */
	public function maybeRedirectSupport($secret_id, $account_id, $envelope = null)
	{

		$this->log("Got to maybeRedirectSupport. ID: $secret_id", __METHOD__, 'debug');

		if (! is_admin()) {
			$redirect_url = get_site_url();
		} else {
			$redirect_url = add_query_arg('page', sanitize_text_field($_GET['page']), admin_url('admin.php'));
		}
		//Get saved settings an then team settings
		$settings = SettingsApi::fromSaved();
		try {
			$teamSettings =  $settings->getByAccountId($account_id);
		} catch (\Exception $e) {
			wp_safe_redirect(add_query_arg(array( 'tl-error' => self::REDIRECT_ERROR_STATUS ), $redirect_url), self::REDIRECT_ERROR_STATUS, 'TrustedLogin');
			exit;
		}
		// first check if l can be redirected.
		if (! $this->verifyUserRole($teamSettings)) {
			$this->log('User cannot be redirected due to auth_verify_user() returning false.', __METHOD__, 'warning');
			return;
		}
		if (is_null($envelope)) {
			// Get the envelope
			$envelope = $this->apiGetEnvelope($secret_id,$account_id);
		}

		if (empty($envelope)) {
			wp_safe_redirect($redirect_url, self::REDIRECT_ERROR_STATUS, 'TrustedLogin');
		}

		if (is_wp_error($envelope)) {
			$this->log('Error: ' . $envelope->get_error_message(), __METHOD__, 'error');
			wp_safe_redirect(add_query_arg(array( 'tl-error' => self::REDIRECT_ERROR_STATUS ), $redirect_url), self::REDIRECT_ERROR_STATUS, 'TrustedLogin');
			exit;
		}

		$envelope_parts = ( $envelope ) ? $this->envelopeToUrl($envelope, true) : false;

		if (is_wp_error($envelope_parts)) {
			wp_safe_redirect(add_query_arg(array( 'tl-error' => self::REDIRECT_ERROR_STATUS ), $redirect_url), self::REDIRECT_ERROR_STATUS, 'TrustedLogin');
			exit;
		}

		$output = $this->get_redirect_form_html($envelope_parts);

		// Use wp_die() to get a nice free template
		wp_die($output, esc_html__('TrustedLogin redirect&hellip;', 'trustedlogin-vendor'), 302);
	}

	/**
	 * Gets the secretId's associated with an access or license key.
	 *
	 * @since  1.0.0
	 *
	 * @param string $access_key The key we're checking for connected sites
	 * @param string $account_id The account ID for access key.
	 * @return array|\WP_Error  Array of siteIds or \WP_Error  on issue.
	 */
	public function apiGetSecretIds($access_key, $account_id)
	{

		if (empty($access_key)) {
			$this->log('Error: access_key cannot be empty.', __METHOD__, 'error');

			return new \WP_Error('data-error', esc_html__('Access Key cannot be empty', 'trustedlogin-vendor'));
		}

		if (! is_user_logged_in()) {
			return new \WP_Error('auth-error', esc_html__('User not logged in.', 'trustedlogin-vendor'));
		}

		$saas_api = $this->plugin->getApiHandler($account_id);
		$response = $saas_api->call(
			'accounts/' . $account_id . '/sites/',
			[
				'searchKeys' => [ $access_key ]
			],
			'POST'
		);

		if (is_wp_error($response)) {
			return $response;
		}


		$this->log('Response: ', __METHOD__, 'debug',['response' => $response]);

		// 204 response: no sites found.
		if (true === $response) {
			return [];
		}

		$access_keys = [];

		if (! empty($response)) {
			foreach ($response as $key => $secrets) {
				foreach ((array) $secrets as $secret) {
					$access_keys[] = $secret;
				}
			}
		}


		return array_reverse($access_keys);
	}

	/**
	 * API Wrapper: Get the envelope for a specified site ID
	 *
	 * @since 0.2.0
	 *
	 * @param string $site_id - unique secret_id of a site
	 *
	 * @return array|false|\WP_Error
	 */
	public function apiGetEnvelope($secret_id, $account_id)
	{

		if (empty($secret_id)) {
			$this->log('Error: secret_id cannot be empty.', __METHOD__, 'error');

			return new \WP_Error('data-error', esc_html__('Site ID cannot be empty', 'trustedlogin-vendor'));
		}

		if (! is_user_logged_in()) {
			return new \WP_Error('auth-error', esc_html__('User not logged in.', 'trustedlogin-vendor'));
		}

		// The data array that will be sent to TrustedLogin to request a site's envelope
		$data = array();

		// Let's grab the user details. Logged in status already confirmed in maybeRedirectSupport();
		$current_user = wp_get_current_user();

		$data['user'] = array( 'id' => $current_user->ID, 'name' => $current_user->display_name );

		// Then let's get the identity verification pair to confirm the site is the one sending the request.
		$trustedlogin_encryption = $this->plugin->getEncryption();
		$auth_nonce              = $trustedlogin_encryption->createIdentityNonce();

		if (is_wp_error($auth_nonce)) {
			return $auth_nonce;
		}

		$data['nonce']       = $auth_nonce['nonce'];
		$data['signedNonce'] = $auth_nonce['signed'];


		$endpoint = 'sites/' . $account_id . '/' . $secret_id . '/get-envelope';

		$saas_api = $this->plugin->getApiHandler($account_id);
		$x_tl_token  = $saas_api->getXTlToken();

		if (is_wp_error($x_tl_token)) {
			$error = esc_html__('Error getting X-TL-TOKEN header', 'trustedlogin-vendor');
			$this->log($error, __METHOD__, 'error');
			return new \WP_Error('x-tl-token-error', $error);
		}

		$token_added = $saas_api->setAdditionalHeader('X-TL-TOKEN', $x_tl_token);

		if (! $token_added) {
			$error = esc_html__('Error setting X-TL-TOKEN header', 'trustedlogin-vendor');
			$this->log($error, __METHOD__, 'error');
			return new \WP_Error('x-tl-token-error', $error);
		}

		$envelope = $saas_api->call($endpoint, $data, 'POST');
		if ($envelope && ! is_wp_error($envelope)) {
			$success = esc_html__('Successfully fetched envelope.', 'trustedlogin-vendor');
		} else {
			$success = sprintf(esc_html__('Failed: %s', 'trustedlogin-vendor'), $envelope->get_error_message());
		}

		return $envelope;
	}

	/**
	 * Helper function: verify the structure of an envelope is valid.
	 *
	 * @since TODO
	 *
	 * @param array|mixed      Envelope to validate
	 *
	 * @return array|\WP_Error Valid envelope or error if invalid.
	 */
	public function verifyEnvelope( $envelope )
	{

		if (empty($envelope)) {
			$this->log('$envelope is empty', __METHOD__, 'error');
			return new \WP_Error( 'empty_envelope', 'The envelope is empty.' );
		}

		if (is_object($envelope)) {
			$envelope = (array) $envelope;
		}

		if (! is_array($envelope)) {
			$this->log('Error: envelope not an array. e:', __METHOD__, 'error',[
				'envelope' => $envelope
			]);

			return new \WP_Error('malformed_envelope', 'The data received is not formatted correctly');
		}

		$required_keys = [ 'identifier', 'siteUrl', 'publicKey', 'nonce' ];

		foreach ($required_keys as $required_key) {
			if (! array_key_exists($required_key, $envelope)) {
				$this->log('Error: malformed envelope.', __METHOD__, 'error', $envelope);

				return new \WP_Error('malformed_envelope', 'The data received is not formatted correctly or there was a server error.');
			}
		}

		return $envelope;
	}

	/**
	 * Helper function: Extract redirect url from encrypted envelope.
	 *
	 * @since 0.1.0
	 *
	 * @param array $envelope Received from encrypted TrustedLogin storage {
	 *
	 * @type string $siteUrl Encrypted site URL
	 * @type string $identifier Encrypted site identifier, used to generate endpoint
	 * @type string $publicKey @TODO
	 * @type string $nonce Nonce from Client {@see \TrustedLogin\Envelope::generate_nonce()} converted to string using \sodium_bin2hex().
	 * @type string $siteUrl URL of the site to access.
	 * }
	 *
	 * @param bool $return_parts Optional. Whether to return an array of parts. Default: false.
	 *
	 * @return string|array|\WP_Error  If $return_parts is false, returns login URL. If true, returns array with login parts. If error, returns \WP_Error .
	 */
	public function envelopeToUrl($envelope, $return_parts = false)
	{

		if ( is_wp_error( $this->verifyEnvelope( $envelope ) ) ) {
			$this->log('Error: envelope not an array. e:', __METHOD__, 'error',[
				'envelope' => $envelope
			]);

			return new \WP_Error('malformed_envelope', 'The data received is not formatted correctly');
		}

		/** var \TrustedLogin\Vendor\Encryption $trustedlogin_encryption */
		$trustedlogin_encryption = $this->plugin->getEncryption();

		try {
			$this->log('Starting to decrypt envelope.', __METHOD__, 'debug',['envelope' => $envelope]);
			$decrypted_identifier = $trustedlogin_encryption->decryptCryptoBox($envelope['identifier'], $envelope['nonce'], $envelope['publicKey']);
			if (is_wp_error($decrypted_identifier)) {
				$this->log('There was an error decrypting the envelope.', __METHOD__,['print_identifier' => $decrypted_identifier]);

				return $decrypted_identifier;
			}

			$this->log('Decrypted identifier: ', __METHOD__, 'debug',['print_identifier' => $decrypted_identifier]);

			$parts = [
				'siteurl'    => $envelope['siteUrl'],
				'identifier' => $decrypted_identifier,
			];
		} catch (\Exception $e) {
			return new \WP_Error($e->getCode(), $e->getMessage());
		}

		$endpoint = $trustedlogin_encryption::hash($parts['siteurl'] . $parts['identifier']);

		if (is_wp_error($endpoint)) {
			return $endpoint;
		}

		$loginurl = $parts['siteurl'] . '/' . $endpoint . '/' . $parts['identifier'];

		if ($return_parts) {
			return [
				'siteurl' => $parts['siteurl'],
				'loginurl'=> $loginurl,
				'endpoint' => $endpoint,
				'identifier' => $parts['identifier']
			];
		}

		return $loginurl;
	}
}
