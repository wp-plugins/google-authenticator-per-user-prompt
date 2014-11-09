There are a few environment specific details that need to be setup in order for the acceptance tests to work.

1) The configuration values in the various Codeception config files must match the environment (e.g., database
   credentials, web server URL, etc).

2) wp-config.php should define DB_NAME as 'wp_dev_tests' if $_SERVER['HTTP_USER_AGENT'] contains 'BrowserKit'
   or 'Guzzle', so that the test database will be used instead of the live one. For example,


   $codeception_request     = false;  // this is also used in mu-plugins/sandbox-functionality.php
   $codeception_user_agents = array( 'BrowserKit', 'Guzzle' );

   foreach ( $codeception_user_agents as $agent ) {
   	if ( false !== strpos( $_SERVER['HTTP_USER_AGENT'], $agent ) ) {
   		$codeception_request = true;
   		break;
   	}
   }

   define( 'DB_NAME', $codeception_request ? 'wp_dev_tests' : 'wp_dev' );


3) There should be an mu-plugin with a `gapup_nonce_expiration` filter callback that returns (int) 5, so that
   we don't have to wait the full 3 minutes during nonce expiration tests. For example,


   /**
    * Expire in 5 seconds when running acceptance tests so don't have to wait forever
    */
   function gapup_nonce_expiration( $expiration ) {
   	global $codeception_request;

   	if ( $codeception_request ) {
   		$expiration = 5;
   	}

   	return $expiration;
   }
   add_filter( 'gapup_nonce_expiration', 'gapup_nonce_expiration' );
