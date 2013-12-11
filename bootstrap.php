<?php
/*
Plugin Name: Google Authenticator - Per User Prompt
Plugin URI:  http://wordpress.org/plugins/google-authenticator-per-user-prompt
Description: Modifies the Google Authenticator plugin so that only users with 2FA enabled are prompted for the authentication token.
Version:     0.1
Author:      Ian Dunn
Author URI:  http://iandunn.name
*/

if ( $_SERVER['SCRIPT_FILENAME'] == __FILE__ )
	die( 'Access denied.' );

define( 'GAPUP_REQUIRED_PHP_VERSION', '5.2.4' );  // because of WordPress minimum requirements
define( 'GAPUP_REQUIRED_WP_VERSION',  '3.5' );    // because of MINUTE_IN_SECONDS

/**
 * Checks if the system requirements are met
 * @return bool True if system requirements are met, false if not
 */
function gapup_requirements_met() {
	global $wp_version;
	require_once( ABSPATH . '/wp-admin/includes/plugin.php' );

	if ( version_compare( PHP_VERSION, GAPUP_REQUIRED_PHP_VERSION, '<' ) ) {
		return false;
	}

	if ( version_compare( $wp_version, GAPUP_REQUIRED_WP_VERSION, '<' ) ) {
		return false;
	}
	
	if ( ! is_plugin_active( 'google-authenticator/google-authenticator.php' ) ) {
		return false;
	}

	return true;
}

/**
 * Prints an error that the system requirements weren't met.
 */
function gapup_requirements_error() {
	global $wp_version;

	require_once( dirname( __FILE__ ) . '/views/requirements-error.php' );
}

/*
 * Check requirements and load main class
 * The main program needs to be in a separate file that only gets loaded if the plugin requirements are met. Otherwise older PHP installations could crash when trying to parse it.
 */
if ( gapup_requirements_met() ) {
	require_once( dirname( __FILE__ ) . '/google-authenticator-per-user-prompt.php' );
	$GLOBALS['gapup'] = new GoogleAuthenticatorPerUserPrompt();
} else {
	add_action( 'admin_notices', 'gapup_requirements_error' );
}