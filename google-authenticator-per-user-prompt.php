<?php

/*
 * This class unhooks Google Authenticator's normal login form workflow, and replaces it with one that separates the 2FA token request
 * into a second step, so that only users who have 2FA enabled will be prompted for it.
 * 
 * The process flows like this:
 * 1) A user enters a valid username and password
 * 2) If they don't have 2FA enabled, of if they're using an application password, they're logged in like normal.
 *    If they do have 2FA enabled, they continue to the next step.
 * 3) We create a nonce and store it in their usermeta
 * 4) Before they are authenticated, we redirect them to a form prompting them for the 2FA token. The nonce is passed in the URL parameters.
 * 5) If they supply the correct nonce and token, we log them in and redirect them to their original destination.
 * 
 * Test cases:
 * User enters correct username/password, has 2FA disabled  => Bypass prompt
 * User enters correct username/password, has 2FA enabled   => Taken to prompt, isn't logged in yet, doesn't receive auth cookies and can't access wp-admin 
 * User enters correct username/password, but nonce expires => Redirected to login
 * User enters correct 2FA token                            => logged in, redirect to original redirect_to parameter
 * User enters incorrect 2FA token                          => Redirected to 2FA form, shown error, can login if enter correct code this time
 * User enters correct application password using XMLRPC    => Logged in, bypasses 2FA token
 * User enters correct application password using web       => Redirected to login form
 * User visits 2FA form directly                            => Redirected to login screen
 * 
 * To check if auth cookies are sent after entering username/password but before entering the 2FA token:
 * curl -i --data "log=username&pwd=password&wp-submit=Log+In&testcookie=1" --cookie "wordpress_test_cookie=WP+Cookie+check" http://example.org/wp-login.php
 */
class GoogleAuthenticatorPerUserPrompt {
	protected $is_using_application_password;
	const ERROR_EXPIRED_NONCE = 100;
	
	/**
	 * Constructor
	 */
	public function __construct() {
		$this->is_using_application_password = false;
		
		add_action( 'init', array( $this, 'register_hook_callbacks' ), 11 );	// have to run after Google Authenticator has registered its callbacks
	}
	
	/*
	 * Register callback methods for WordPress hooks
	 */
	public function register_hook_callbacks() {
		// Remove Google Authenticator's callbacks
		remove_action( 'login_form',          array( GoogleAuthenticator::$instance, 'loginform' ) );
		remove_filter( 'authenticate',        array( GoogleAuthenticator::$instance, 'check_otp' ), 50, 3 );

		// Register our callbacks
		add_filter( 'authenticate',           array( $this, 'validate_application_password' ), 10, 3 );    // before username/password check
		add_filter( 'authenticate',           array( $this, 'maybe_prompt_for_token' ), 25, 3 );           // after username/password check, but before cookie check
		add_action( 'login_form_gapup_token', array( $this, 'prompt_for_token' ) );
		add_filter( 'wp_login_errors',        array( $this, 'get_login_error_message' ) );
	}

	/**
	 * Checks if the submitted password was a valid application password
	 * This is basically copied from GoogleAuthenticator::check_opt(), so it'll need to be kept in sync if changes are ever made over there
	 * See process_token_form() for why this is necessary, instead of just relying on check_otp()
	 * This is called by the 'authenticate' filter, while WordPress is processing a submitted username/password from the login form
	 *
	 * @param  int    $user_id
	 * @param  string $password
	 * @return bool
	 */
	public function validate_application_password( $user, $username, $attempted_password ) {
		$user = get_user_by( 'login', $username );
		$is_application_request = ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'APP_REQUEST' ) && APP_REQUEST );
		
		if ( isset( $user->ID ) && 'enabled' == trim( get_user_option( 'googleauthenticator_pwdenabled', $user->ID ) ) && $is_application_request ) {
			$valid_password     = json_decode( get_user_option( 'googleauthenticator_passwords', $user->ID ) );
			$valid_password     = trim( $valid_password->{'password'} );
			$attempted_password = sha1( strtoupper( str_replace( ' ', '', $attempted_password ) ) );

			if ( $attempted_password === $valid_password ) {
				$this->is_using_application_password = true;
				return $user;
			}
		}

		return null;
	}

	/**
	 * Redirects the user to the token prompt if they have 2FA enabled
	 * If they don't have 2FA enabled, this does nothing and they proceed to the Administration Panels like normal
	 * Login attempts with an application password are also allowed to bypass 2FA
	 * This is called during the authenticate filter, after the user has entered a username/password
	 * 
	 * @param  mixed   $user
	 * @param  string  $username
	 * @param  string  $attempted_password
	 * @return mixed
	 */
	public function maybe_prompt_for_token( $user, $username, $attempted_password ) {
		if ( is_a( $user, 'WP_User' ) ) {	// they entered a valid username/password
			if ( 'enabled' == trim( get_user_option( 'googleauthenticator_enabled', $user->ID ) ) && ! $this->is_using_application_password ) {
				$login_nonce  = $this->create_login_nonce( $user->ID );
				$redirect_url = sprintf(
					'%s?action=gapup_token&user_id=%d&gapup_login_nonce=%s%s',
					wp_login_url(),
					$user->ID,
					$login_nonce['nonce'],
					isset( $_REQUEST['redirect_to'] ) ? '&redirect_to=' . urlencode( $_REQUEST['redirect_to'] ) : ''
				);
				
				wp_safe_redirect( $redirect_url );
				die();
			}	
		}
		
		return $user;
	}

	/**
	 * Creates a nonce when the user successfully logs in with a username and password
	 * If they later supply this when entering a correct 2FA token, then we can know that they previously logged in with a correct username/password
	 *
	 * @param $user_id
	 * @return array|bool
	 */
	function create_login_nonce( $user_id ) {
		$login_nonce = array(
			'nonce'      => wp_hash( $user_id . mt_rand() . microtime(), 'nonce' ),
			'expiration' => time() + apply_filters( 'gapup_nonce_expiration', MINUTE_IN_SECONDS * 3 )
		);

		update_user_meta( $user_id, 'gapup_login_nonce', $login_nonce );

		return $login_nonce;
	}

	/**
	 * Renders the form that prompts the user for their 2FA token, and handles the submitted form
	 * Is called during the login_form_gapup_token action, when the user is redirect to the [login_url]?action=gapup_token screen, after entering a correct username/password.
	 * The user can also access this by directly visiting [login_url]?action=gapup_token&user_id=[id], which would let them attempt to bypass entering a username/password, 
	 * so we detect that they didn't provide a valid nonce and redirect them back to the login screen.
	 */
	public function prompt_for_token() {
		$redirect_to = isset( $_REQUEST['redirect_to'] ) ? $_REQUEST['redirect_to'] : '';
		$action_url  = add_query_arg( array( 'action' => 'gapup_token' ), wp_login_url( $redirect_to ) );
		
		if ( ! isset( $_REQUEST['user_id'] ) || ! isset( $_REQUEST['gapup_login_nonce'] ) ) {
			return;
		}

		$user = get_user_by( 'id', absint( $_REQUEST['user_id'] ) );
		
		if ( ! $user ) {
			return;
		}
		
		$error_message = $this->process_token_form( $_POST, $user );

		require_once( dirname( __FILE__ ) . '/views/token-prompt.php' );
		exit();
	}

	/**
	 * Process the submitted 2FA token form
	 * The user's submitted password isn't passed to check_otp() because we would need a way to securely store it in plaintext between the time it was entered and
	 * when we use it here. Because of this, check_opt() won't authenticate application passwords, so we're checking for those in maybe_prompt_for_token() instead.
	 * 
	 * @param  array   $form
	 * @param  WP_User $user
	 * @return string  The error that occurred during processing, if any
	 */
	protected function process_token_form( $form, $user ) {
		$error_message = '';
		
		if ( isset( $form['gapup_token_prompt'] ) ) {
			$user = GoogleAuthenticator::$instance->check_otp( $user, $user->user_login, null );

			if ( is_a( $user, 'WP_User' ) ) {
				$error_message = $this->login_user( $user );
			} elseif ( is_wp_error( $user ) ) {
				$error_message = $user->get_error_message();
			} else {
				$error_message = '<strong>ERROR:</strong> Token could not be validated';
			}
		}
		
		return $error_message;
	}
	
	/**
	 * Logs the user in
	 * This is called after the user has successfully entered a token
	 */
	protected function login_user( $user ) {
		remove_action( 'wp_login',  array( $this, 'maybe_prompt_for_token' ), 10, 2 );	// otherwise the user would be logged out and redirected back to the token form
		add_action( 'authenticate', array( $this, 'verify_original_login' ), 40, 3 );   // after username/password and cookie checks
		$user = wp_signon( array( 'user_login' => $user->user_login ) );
		remove_action( 'authenticate', array( $this, 'verify_original_login' ), 40, 3 );
		
		if ( is_a( $user, 'WP_User' ) ) {
			$redirect_url = isset( $_REQUEST['redirect_to'] ) ? $_REQUEST['redirect_to'] : admin_url();
			wp_safe_redirect( $redirect_url );
			die();
		} elseif( is_wp_error( $user ) ) {	// will only get here if another plugin has an 'authenticate' filter running after ours
			return $user->get_error_message();
		} else {
			return '<strong>ERROR:</strong> Login attempt failed.';
		}
	}

	/**
	 * Verifies that the user logged in with a valid username/password earlier in their login attempt
	 * If we didn't do this, someone could just visit the 2FA form directly, then enter a correct 2FA token and bypass the username/password check
	 * This is called after the user enters a correct 2FA token
	 * 
	 * @param WP_User $user
	 * @param string  $username
	 * @param string  $password
	 */
	public function verify_original_login( $user, $username, $password ) {
		$user = get_user_by( 'login', $username );
		
		if ( $this->verify_login_nonce( $user->ID, $_POST['gapup_login_nonce'] ) ) {
			return $user;
		} else {
			$redirect_url = sprintf(
				'%s?gapup_error=%s%s',
				wp_login_url(),
				self::ERROR_EXPIRED_NONCE,
				isset( $_REQUEST['redirect_to'] ) ? '&redirect_to=' . urlencode( $_REQUEST['redirect_to'] ) : ''
			);
			
			wp_safe_redirect( $redirect_url );
		}
	}

	/**
	 * Verify that the nonce the user submitted matches the one we gave them when they logged in, and that it hasn't expired 
	 * 
	 * @param  int    $user_id
	 * @param  string $attempted_nonce
	 * @return bool
	 */
	function verify_login_nonce( $user_id, $attempted_nonce ) {
		$login_nonce = array_shift( get_user_meta( $user_id, 'gapup_login_nonce' ) );
		$valid       = false;
		
		if ( isset( $login_nonce['nonce'] ) && $attempted_nonce === $login_nonce['nonce'] && time() < $login_nonce['expiration'] ) {
			delete_user_meta( $user_id, 'gapup_login_nonce' );	// so it can only be used once
			$valid = true;
		}

		return $valid;
	}

	/**
	 * Adds error messages to the username/password screen when they were passed by URL parameters
	 * 
	 * @param  WP_Error $errors
	 * @return WP_Error
	 */
	public function get_login_error_message( $errors ) {
		$code = isset( $_REQUEST['gapup_error'] ) ? $_REQUEST['gapup_error'] : null;
		
		switch( $code ) {
			case self::ERROR_EXPIRED_NONCE:
				$errors->add( 'gapup_' . self::ERROR_EXPIRED_NONCE, '<strong>ERROR:</strong> Your login nonce has expired. Please log in again.' );
			break;
		}
		
		return $errors;
	}
} // end GoogleAuthenticatorPerUserPrompt