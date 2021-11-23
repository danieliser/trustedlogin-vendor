<?php
namespace TrustedLogin\Vendor;

use TrustedLogin\Vendor\Traits\Logger;
use TrustedLogin\Vendor\AuditLog;
use TrustedLogin\Vendor\Plugin;

/**
 * High-level API for SaaS interactions
 *
 * Methods include validation, logging and API calls
 *
 */
class TrustedLoginService {

	use Logger;
	/**
	 * @var Plugin
	 */
	protected $plugin;
	public function __construct(Plugin $plugin) {
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
	public function handle_multiple_secret_ids( $account_id,$secret_ids = array() ) {

		if ( ! is_array( $secret_ids ) || empty( $secret_ids ) ) {
			return;
		}

		$urls_output  = '';
		$url_template = '<li><a href="%1$s" class="%2$s">%3$s</a></li>';
		$valid_ids    = array();

		foreach ( $secret_ids as $secret_id ) {

			$envelope = $this->api_get_envelope( $secret_id );

			if ( is_wp_error( $envelope ) ) {
				$this->log( 'Error: ' . $envelope->get_error_message(), __METHOD__, 'error' );
				continue;
			}

			if ( empty( $envelope ) ) {
				$this->log( '$envelope is empty', __METHOD__, 'error' );
				continue;
			}

			$this->log( '$envelope is not an error. Here\'s the envelope: ' . print_r( $envelope, true ), __METHOD__, 'debug' );

			// TODO: Convert to shared (client/vendor) Envelope library
			$url_parts = $this->envelope_to_url( $envelope, true );

			if ( is_wp_error( $url_parts ) ) {
				$this->log( 'Error: ' . $url_parts->get_error_message(), __METHOD__, 'error' );
				continue;
			}

			if ( empty( $url_parts ) ) {
				continue;
			}

			$urls_output .= sprintf(
				$url_template,
				esc_url( $url_parts['loginurl'] ),
				esc_attr( 'trustedlogin-authlink' ),
				sprintf( esc_html__( 'Log in to %s', 'trustedlogin-vendor' ), esc_html( $url_parts['siteurl'] ) )
			);

			$valid_ids[] = array(
				'id' => $secret_id,
				'envelope' => $envelope,
			);
		}

		if ( 1 === sizeof( $valid_ids ) ) {
			reset( $valid_ids );
			$this->maybe_redirect_support( $valid_ids[0]['id'], $valid_ids[0]['envelope'] );
		}

		if ( empty( $urls_output ) ) {
			return;
		}

		add_action( 'admin_notices', function () use ( $urls_output ) {
			echo '<div class="notice notice-warning"><h3>' . esc_html__( 'Choose a site to log into:', 'trustedlogin-vendor' ) . '</h3><ul>' . $urls_output . '</ul></div>';
		} );

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
	public function maybe_redirect_support( $secret_id,$account_id, $envelope = null ) {

		$this->log( "Got to maybe_redirect_support. ID: $secret_id", __METHOD__, 'debug' );

		if ( ! is_admin() ) {
			$redirect_url = get_site_url();
		} else {
			$redirect_url = add_query_arg( 'page', sanitize_text_field( $_GET['page'] ), admin_url( 'admin.php' ) );
		}

		// first check if user can be redirected.
		if ( ! $this->auth_verify_user() ) {
			$this->log( 'User cannot be redirected due to auth_verify_user() returning false.', __METHOD__, 'warning' );

			return;
		}

		if ( is_null( $envelope ) ) {
			// Get the envelope
			$envelope = $this->api_get_envelope( $secret_id );
		}

		if ( empty( $envelope ) ) {
			$this->plgin->getAuditLog()->insert( $secret_id, 'failed', esc_html__( 'Empty envelope.', 'trustedlogin-vendor' ) );
			wp_safe_redirect( $redirect_url, self::REDIRECT_ERROR_STATUS, 'TrustedLogin' );
		}

		if ( is_wp_error( $envelope ) ) {
			$this->log( 'Error: ' . $envelope->get_error_message(), __METHOD__, 'error' );
			$this->getAuditLog()->insert( $secret_id, 'failed', $envelope->get_error_message() );
			wp_safe_redirect( add_query_arg( array( 'tl-error' => self::REDIRECT_ERROR_STATUS ), $redirect_url ), self::REDIRECT_ERROR_STATUS, 'TrustedLogin' );
			exit;
		}

		$envelope_parts = ( $envelope ) ? $this->envelope_to_url( $envelope, true ) : false;

		if ( is_wp_error( $envelope_parts ) ) {
			$this->getAuditLog()->insert( $secret_id, 'failed', $envelope_parts->get_error_message() );
			wp_safe_redirect( add_query_arg( array( 'tl-error' => self::REDIRECT_ERROR_STATUS ), $redirect_url ), self::REDIRECT_ERROR_STATUS, 'TrustedLogin' );
			exit;
		}

		if( ! isset( $envelope_parts['siteurl'], $envelope_parts['endpoint'], $envelope_parts['identifier'] ) ) {
			$this->getAuditLog()->insert( $secret_id, 'failed', esc_html__( 'Malformed envelope.', 'trustedlogin-vendor' ) );
		}

		$output = $this->get_redirect_form_html( $envelope_parts );

		$this->getAuditLog()->insert( $secret_id, 'redirected', esc_html__( 'Successful decryption of the envelope. Presenting the redirect form.', 'trustedlogin-vendor' ) );

		// Use wp_die() to get a nice free template
		wp_die( $output, esc_html__( 'TrustedLogin redirect&hellip;', 'trustedlogin-vendor' ), 302 );
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
	public function api_get_secret_ids( $access_key,$account_id ) {

		if ( empty( $access_key ) ) {
			$this->log( 'Error: access_key cannot be empty.', __METHOD__, 'error' );

			return new \WP_Error ( 'data-error', esc_html__( 'Access Key cannot be empty', 'trustedlogin-vendor' ) );
		}

		if ( ! is_user_logged_in() ) {
			return new \WP_Error ( 'auth-error', esc_html__( 'User not logged in.', 'trustedlogin-vendor' ) );
		}

		$saas_api = $this->plugin->getApiHandler($account_id);
		$response = $saas_api->call(
			'accounts/' . $account_id . '/sites/',
		 	[
				'searchKeys' => [ $access_key ]
			],
			'POST'
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$this->log( 'Response: ' . print_r( $response, true ), __METHOD__, 'debug' );

		// 204 response: no sites found.
		if ( true === $response ) {
			return [];
		}

		$access_keys = [];

		if ( ! empty( $response ) ) {
			foreach ( $response as $key => $secrets ) {
				foreach ( (array) $secrets as $secret ) {
					$access_keys[] = $secret;
				}
			}
		}

		return array_reverse( $access_keys );
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
	public function api_get_envelope( $secret_id,$account_id ) {

		if ( empty( $secret_id ) ) {
			$this->log( 'Error: secret_id cannot be empty.', __METHOD__, 'error' );

			return new \WP_Error ( 'data-error', esc_html__( 'Site ID cannot be empty', 'trustedlogin-vendor' ) );
		}

		if ( ! is_user_logged_in() ) {
			return new \WP_Error ( 'auth-error', esc_html__( 'User not logged in.', 'trustedlogin-vendor' ) );
		}

		// The data array that will be sent to TrustedLogin to request a site's envelope
		$data = array();

		// Let's grab the user details. Logged in status already confirmed in maybe_redirect_support();
		$current_user = wp_get_current_user();

		$data['user'] = array( 'id' => $current_user->ID, 'name' => $current_user->display_name );

		// Then let's get the identity verification pair to confirm the site is the one sending the request.
		$trustedlogin_encryption = $this->plugin->getEncryption();
		$auth_nonce              = $trustedlogin_encryption->create_identity_nonce();

		if ( is_wp_error( $auth_nonce ) ) {
			return $auth_nonce;
		}

		$data['nonce']       = $auth_nonce['nonce'];
		$data['signedNonce'] = $auth_nonce['signed'];

		$this->plugin->auditLog->insert( $secret_id, 'requested' );

		$endpoint = 'sites/' . $account_id . '/' . $secret_id . '/get-envelope';

		$saas_api = $this->plugin->getApiHandler($account_id);
		$x_tl_token  = $saas_api->get_x_tl_token();

		if ( is_wp_error( $x_tl_token ) ) {
			$error = esc_html__( 'Error getting X-TL-TOKEN header', 'trustedlogin-vendor' );
			$this->log( $error, __METHOD__, 'error' );
			return new \WP_Error ( 'x-tl-token-error', $error );
		}

		$token_added = $saas_api->set_additional_header( 'X-TL-TOKEN', $x_tl_token );

		if ( ! $token_added ) {
			$error = esc_html__( 'Error setting X-TL-TOKEN header', 'trustedlogin-vendor' );
			$this->log( $error, __METHOD__, 'error' );
			return new \WP_Error ( 'x-tl-token-error', $error );
		}

		$envelope = $saas_api->call( $endpoint, $data, 'POST' );

		if ( $envelope && ! is_wp_error( $envelope ) ) {
			$success = esc_html__( 'Successfully fetched envelope.', 'trustedlogin-vendor' );
		} else {
			$success = sprintf( esc_html__( 'Failed: %s', 'trustedlogin-vendor' ), $envelope->get_error_message() );
		}

		$this->plugin->auditLog()->insert( $secret_id, 'received', $success );

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
	public function envelope_to_url( $envelope, $return_parts = false ) {

		if ( is_object( $envelope ) ) {
			$envelope = (array) $envelope;
		}

		if ( ! is_array( $envelope ) ) {
			$this->log( 'Error: envelope not an array. e:' . print_r( $envelope, true ), __METHOD__, 'error' );

			return new WP_Error( 'malformed_envelope', 'The data received is not formatted correctly' );
		}

		$required_keys = [ 'identifier', 'siteUrl', 'publicKey', 'nonce' ];

		foreach ( $required_keys as $required_key ) {
			if ( ! array_key_exists( $required_key, $envelope ) ) {
				$this->log( 'Error: malformed envelope.', __METHOD__, 'error', $envelope );

				return new \WP_Error ( 'malformed_envelope', 'The data received is not formatted correctly or there was a server error.' );
			}
		}

		/** var \TrustedLogin\Vendor\Encryption $trustedlogin_encryption */
		$trustedlogin_encryption = $this->plugin->getEncryption();

		try {

			$this->log( 'Starting to decrypt envelope. Envelope: ' . print_r( $envelope, true ), __METHOD__, 'debug' );

			$decrypted_identifier = $trustedlogin_encryption->decrypt_crypto_box( $envelope['identifier'], $envelope['nonce'], $envelope['publicKey'] );

			if ( is_wp_error( $decrypted_identifier ) ) {

				$this->log( 'There was an error decrypting the envelope.' . print_r( $decrypted_identifier, true ), __METHOD__ );

				return $decrypted_identifier;
			}

			$this->log( 'Decrypted identifier: ' . print_r( $decrypted_identifier, true ), __METHOD__, 'debug' );

			$parts = [
				'siteurl'    => $envelope['siteUrl'],
				'identifier' => $decrypted_identifier,
			];

		} catch ( \Exception $e ) {
			return new \WP_Error ( $e->getCode(), $e->getMessage() );
		}

		$endpoint = $trustedlogin_encryption::hash( $parts['siteurl'] . $parts['identifier'] );

		if ( is_wp_error( $endpoint ) ) {
			return $endpoint;
		}

		$loginurl = $parts['siteurl'] . '/' . $endpoint . '/' . $parts['identifier'];

		if ( $return_parts ){

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
