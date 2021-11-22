<?php
/**
 * Plugin Name: TrustedLogin Support Plugin
 * Plugin URI: https://www.trustedlogin.com
 * Description: Authenticate support team members to securely log them in to client sites via TrustedLogin
 * Version: 0.10.0
 * Requires PHP: 5.4
 * Author: Katz Web Services, Inc.
 * Author URI: https://www.trustedlogin.com
 * Text Domain: trustedlogin-vendor
 * License: GPL v2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Copyright: © 2020 Katz Web Services, Inc.
 */
namespace TrustedLogin\Vendor;

use Exception;

if (!defined('ABSPATH')) {
    exit;
}
// Exit if accessed directly

define( 'TRUSTEDLOGIN_PLUGIN_VERSION', '0.10.0' );
define( 'TRUSTEDLOGIN_PLUGIN_FILE', __FILE__ );
if( ! defined( 'TRUSTEDLOGIN_API_URL')){
	define( 'TRUSTEDLOGIN_API_URL', 'https://app.trustedlogin.com/api/v1/' );
}


/** @define "$path" "./" */
$path = plugin_dir_path(__FILE__);

register_deactivation_hook( __FILE__, 'trustedlogin_vendor_deactivate' );

function trustedlogin_vendor_deactivate() {
	delete_option( 'tl_permalinks_flushed' );
	delete_option( 'trustedlogin_vendor_config' );
}


function trustedlogin_vendor_init($path){
	if( file_exists( $path . 'vendor/autoload.php' ) ){
		include_once $path . 'vendor/autoload.php';
        include_once dirname( __FILE__ ). '/inc/functions.php';
        include_once dirname( __FILE__ ). '/inc/hooks.php';
        include_once dirname( __FILE__ ) . '/admin/trusted-login-settings/init.php';
        include_once dirname( __FILE__ ) . '/admin/trusted-login-access/init.php';
		do_action( 'trustedlogin_vendor' );
	}else{
		throw new Exception('1');
	}

}


trustedlogin_vendor_init($path);
