<?php

use \Codeception\Scenario;

class Google_Authenticator_Per_User_Prompt_Acceptance_Tests {
	protected $current_otp;

	const VALID_USER_ID                   = 2;
	const VALID_USERNAME                  = '2fa-tester';
	const VALID_PASSWORD                  = 'password';
	const VALID_APPLICATION_PASSWORD      = 'YUKP HO6T Z5WB QW3N';
	const INVALID_USERNAME                = 'fake-user';
	const INVALID_PASSWORD                = 'fake-password';
	const INVALID_OTP                     = '000000';
	const INVALID_NONCE                   = '00000000000000000000000000000000';
	const INVALID_APPLICATION_PASSWORD    = 'fake-password';
	const OTP_SECRET                      = 'FSFMTBLXN52ALUSY';
	const OTP_LIFETIME_SECONDS            = 30;
	const OTP_DRIFT_TOLERANCE_SECONDS     = 30;
	const NONCE_LIFETIME_SECONDS          = 5;	// see ../readme.txt
	const AUTH_COOKIE_REMEMBERED_DAYS     = 14;
	const AUTH_COOKIE_NOT_REMEMBERED_DAYS = 2;

	/**
	 * Generate the current OTP.
	 *
	 * Borrowed heavily from https://www.idontplaydarts.com/2011/07/google-totp-two-factor-authentication-for-php/
	 *
	 * @todo This should be in the helper, but I'm not sure there'd be a way to access the
	 *       value. It wouldn't be returned, and codeception uses call_user_func(), which won't pass
	 *       by reference.
	 *       Probably extract these two functions into a separate class, and just require and call that.
	 *       Require it on setup().
	 *
	 */
	protected function getCurrentOtp() {
		require_once( dirname( dirname( dirname( __DIR__ ) ) ) . '/google-authenticator/base32.php' );

		$digits         = 6;
		$secret_binary  = Base32::decode( self::OTP_SECRET );
		$timecode       = ( time() * 1000 ) / ( self::OTP_LIFETIME_SECONDS * 1000 );
		$counter_binary = pack( 'N*', 0 ) . pack( 'N*', $timecode );
		$hash           = hash_hmac( 'sha1', $counter_binary, $secret_binary, true );
		$otp            = $this->oathTruncate( $hash, $digits );

		$this->current_otp = str_pad( $otp, $digits, '0', STR_PAD_LEFT );
	}

	/**
	 * Extracts the OTP from the SHA1 hash.
	 *
	 * Modified from https://www.idontplaydarts.com/2011/07/google-totp-two-factor-authentication-for-php/
	 *
	 * @param string $hash
	 * @param int $digits
	 * @return integer
	 */
	protected function oathTruncate( $hash, $digits ) {
		$offset = ord( $hash[19] ) & 0xf;

		$code = (
			( ( ord( $hash[ $offset + 0 ] ) & 0x7f ) << 24 ) |
			( ( ord( $hash[ $offset + 1 ] ) & 0xff ) << 16 ) |
			( ( ord( $hash[ $offset + 2 ] ) & 0xff ) << 8 ) |
			( ord( $hash[ $offset + 3 ] ) & 0xff )
		);

		return $code % pow( 10, $digits );
	}

	/**
	 * Attempt to login with an invalid username or password.
	 *
	 * Conditions:
	 *     2FA status is:         Enabled or Disabled
	 *     Username/password are: Invalid
	 *
	 * Action:           Send every possible combination of invalid username and password.
	 * Expected results: The user is redirected back to wp-login.php.
	 *                   The user is shown an error.
	 *                   The user is not logged in.
	 *
	 * @group 2fa_enabled
	 * @group 2fa_disabled
	 * @group invalid_username_password
	 *
	 * @param WebGuy   $i
	 * $param Scenario $scenario
	 */
	public function login_with_invalid_username_or_password( WebGuy $i, Scenario $scenario ) {
		$i->wantTo( 'Log in with an invalid username or password.' );

		$i->login( self::VALID_USERNAME, self::INVALID_PASSWORD );
		$i->see( sprintf( 'The password you entered for the username %s is incorrect.', self::VALID_USERNAME ), '#login_error' );
		$i->seeInCurrentUrl( 'wp-login.php' );
		$i->amNotLoggedIn( self::VALID_USERNAME );

		$i->login( self::INVALID_USERNAME, self::VALID_PASSWORD );
		$i->see( 'Invalid username.', '#login_error' );
		$i->seeInCurrentUrl( 'wp-login.php' );
		$i->amNotLoggedIn( self::INVALID_USERNAME );
		
		$i->login( self::INVALID_USERNAME, self::INVALID_PASSWORD );
		$i->see( 'Invalid username.', '#login_error' );
		$i->seeInCurrentUrl( 'wp-login.php' );
		$i->amNotLoggedIn( self::INVALID_USERNAME );
	}
	
	/**
	 * Attempt to login when 2FA is disabled.
	 *
	 * Conditions:
	 *     2FA status is:         Disabled
	 *     Username/password are: Valid
	 *
	 * Action:           Send valid username and password.
	 * Expected results: The user is not redirected to the 2FA token prompt screen.
	 *                   The user is logged in.
	 *
	 * @group 2fa_disabled
	 * @group valid_username_password
	 *
	 * @param WebGuy   $i
	 * $param Scenario $scenario
	 */
	public function login_with_2fa_disabled( WebGuy $i, Scenario $scenario ) {
		$i->wantTo( 'Log in when 2FA is disabled.' );

		$i->login( self::VALID_USERNAME, self::VALID_PASSWORD );
		$i->amLoggedIn( self::VALID_USERNAME );
	}

	/**
	 * Attempt to login with a valid OTP.
	 *
	 * Conditions:
	 *     2FA status is:         Enabled
	 *     Username/password are: Valid
	 *     OTP is:                Valid
	 *     Nonce is:              Valid
	 *
	 * Action:           Send valid username/password.
	 * Expected results: The user is redirected to the 2FA token prompt screen.
	 *                   The user is not logged in yet.
	 * Action:           Send a valid OTP.
	 * Expected results: The user is redirected to wp-admin
	 *                   The user is logged in.
	 *
	 * @group 2fa_enabled
	 * @group valid_username_password
	 * @group valid_otp
	 * @group valid_nonce
	 *
	 * @param WebGuy   $i
	 * @param Scenario $scenario
	 */
	public function login_with_valid_otp( WebGuy $i, Scenario $scenario ) {
		$i->wantTo( 'Log in with a valid OTP.' );

		$i->enable2fa( self::VALID_USER_ID );
		$i->login( self::VALID_USERNAME, self::VALID_PASSWORD );
		$i->amNotLoggedIn( self::VALID_USERNAME );
		$this->getCurrentOtp();
		$i->sendOtp( $this->current_otp );
		$i->amLoggedIn( self::VALID_USERNAME );
	}

	/**
	 * Login with the 'Remember Me' flag enabled.
	 *
	 * Conditions:
	 *     2FA status is:         Enabled
	 *     Username/password are: Valid
	 *     OTP is:                Valid
	 *     Nonce is:              Valid
	 *
	 * Action:           Login with the 'Remember Me' flag enabled.
	 * Expected results: The user is redirected to the 2FA token prompt screen.
	 *                   The user is not logged in yet.
	 * Action:           Send a valid OTP.
	 * Expected results: The user is redirected to wp-admin
	 *                   The user is logged in.
	 *                   The auth cookie expiration has been extended.
	 *
	 * @group 2fa_enabled
	 * @group valid_username_password
	 * @group valid_otp
	 * @group valid_nonce
	 * @group remember_me
	 *
	 * @param WebGuy   $i
	 * @param Scenario $scenario
	 */
	public function login_with_remember_me_enabled( WebGuy $i, Scenario $scenario ) {
		$i->wantTo( "Log in with the 'Remember Me' flag enabled." );

		$i->enable2fa( self::VALID_USER_ID );
		$i->login( self::VALID_USERNAME, self::VALID_PASSWORD, false, true );
		$i->amNotLoggedIn( self::VALID_USERNAME );
		$this->getCurrentOtp( $i, $scenario, self::VALID_USERNAME );
		$i->sendOtp( $this->current_otp );
		$i->amLoggedIn( self::VALID_USERNAME );
		$i->seeAuthCookieExpiresInDays( self::VALID_USER_ID, self::AUTH_COOKIE_REMEMBERED_DAYS );
	}

	/**
	 * Login with the 'Remember Me' flag disabled.
	 *
	 * Conditions:
	 *     2FA status is:         Enabled
	 *     Username/password are: Valid
	 *     OTP is:                Valid
	 *     Nonce is:              Valid
	 *
	 * Action:           Login with the 'Remember Me' flag disabled.
	 * Expected results: The user is redirected to the 2FA token prompt screen.
	 *                   The user is not logged in yet.
	 * Action:           Send a valid OTP.
	 * Expected results: The user is redirected to wp-admin
	 *                   The user is logged in.
	 *                   The auth cookie expiration has not been extended.
	 *
	 * @group 2fa_enabled
	 * @group valid_username_password
	 * @group valid_otp
	 * @group valid_nonce
	 * @group remember_me
	 *
	 * @param WebGuy   $i
	 * @param Scenario $scenario
	 */
	public function login_with_remember_me_disabled( WebGuy $i, Scenario $scenario ) {
		$i->wantTo( "Log in with the 'Remember Me' flag disabled." );

		$i->enable2fa( self::VALID_USER_ID );
		$i->login( self::VALID_USERNAME, self::VALID_PASSWORD, false, true );
		$i->amNotLoggedIn( self::VALID_USERNAME );
		$this->getCurrentOtp( $i, $scenario, self::VALID_USERNAME );
		$i->sendOtp( $this->current_otp );
		$i->amLoggedIn( self::VALID_USERNAME );
		$i->seeAuthCookieExpiresInDays( self::VALID_USER_ID, self::AUTH_COOKIE_NOT_REMEMBERED_DAYS );
	}

	/**
	 * Attempt to login with an expired nonce.
	 *
	 * Conditions:
	 *     2FA status is:         Enabled
	 *     Username/password are: Valid
	 *     OTP is:                Valid
	 *     Nonce is:              Expired
	 *
	 * Action:           Send valid username/password.
	 * Expected results: The user is redirected to the 2FA token prompt screen.
	 *                   The user is not logged in yet.
	 * Action:           Send a valid OTP.
	 *                   Wait until the nonce expires.
	 * Expected results: The user is redirected back to wp-login.php
	 *                   The user is not logged in.
	 *                   The user sees an error message.
	 *
	 * @group 2fa_enabled
	 * @group valid_username_password
	 * @group valid_otp
	 * @group expired_nonce
	 * @group wait
	 *
	 * @param WebGuy   $i
	 * @param Scenario $scenario
	 */
	public function login_with_expired_nonce( WebGuy $i, Scenario $scenario ) {
		$i->wantTo( 'Login with an expired nonce.' );

		$i->enable2fa( self::VALID_USER_ID );
		$i->login( self::VALID_USERNAME, self::VALID_PASSWORD );
		$i->amNotLoggedIn( self::VALID_USERNAME );

		$this->getCurrentOtp( $i, $scenario, self::VALID_USERNAME );
		if ( $scenario->running() ) {
			sleep( self::NONCE_LIFETIME_SECONDS + 1 );
		}
		$i->sendOtp( $this->current_otp );

		$i->amNotLoggedIn( self::VALID_USERNAME );
		$i->see( 'Your login nonce has expired. Please log in again.', '#login_error' );
	}

	/**
	 * Attempt to login with an expired OTP.
	 *
	 * Conditions:
	 *     2FA status is:         Enabled
	 *     Username/password are: Valid
	 *     OTP is:                Expired
	 *     Nonce is:              Valid
	 *
	 * Action:           Send valid username/password.
	 * Expected results: The user is redirected to the 2FA token prompt screen.
	 *                   The user is not logged in yet.
	 * Action:           Send an expired OTP.
	 * Expected results: The user is redirected back to wp-login.php
	 *                   The user is not logged in.
	 *                   The user sees an error message.
	 *
	 * @group 2fa_enabled
	 * @group valid_username_password
	 * @group expired_otp
	 * @group valid_nonce
	 * @group wait
	 *
	 * @param WebGuy   $i
	 * @param Scenario $scenario
	 */
	public function login_with_expired_otp( WebGuy $i, Scenario $scenario ) {
		$i->wantTo( 'Login with an expired OTP.' );

		$i->enable2fa( self::VALID_USER_ID );
		$i->login( self::VALID_USERNAME, self::VALID_PASSWORD );
		$i->amNotLoggedIn( self::VALID_USERNAME );

		$this->getCurrentOtp( $i, $scenario, self::VALID_USERNAME );
		if ( $scenario->running() ) {
			sleep( self::OTP_LIFETIME_SECONDS + self::OTP_DRIFT_TOLERANCE_SECONDS + 1 );
		}
		$i->sendOtp( $this->current_otp );

		// todo shouldn't this be failing because NONCE_LIFETIME is < OTP_LIFETIME + OTP_DRIFT_TOLERANCE? does that indicate something is broke w/ nonce expiration code in verify_login_nonce()?

		$i->amNotLoggedIn( self::VALID_USERNAME );
		$i->see( 'The Google Authenticator code is incorrect or has expired.', '#login_error' );
	}

	/**
	 * Attempt to log in and be redirected to the original requested location.
	 *
	 * Conditions:
	 *     2FA status is:         Enabled
	 *     Username/password are: Valid
	 *     OTP is:                Valid
	 *     Nonce is:              Valid
	 *
	 * Action:           Send a valid username/password.
	 * Expected results: The user is redirected to the 2FA token prompt screen.
	 *                   The user is not logged in yet.
	 * Action:           Enter a valid OTP.
	 * Expected results: The user is logged in.
	 *                   The user is redirected to the redirect_to parameter.
	 *
	 * @group 2fa_enabled
	 * @group valid_username_password
	 * @group valid_otp
	 * @group valid_nonce
	 * @group redirect_to
	 *
	 * @param WebGuy   $i
	 * @param Scenario $scenario
	 */
	public function login_with_redirect_to( WebGuy $i, Scenario $scenario ) {
		$i->wantTo( 'Log in and then be redirected to my original destination.' );
		$redirect_to = '/wp-admin/edit.php';

		$i->enable2fa( self::VALID_USER_ID );
		$i->login( self::VALID_USERNAME, self::VALID_PASSWORD, $redirect_to );
		$i->amNotLoggedIn( self::VALID_USERNAME );
		$this->getCurrentOtp( $i, $scenario, self::VALID_USERNAME );
		$i->sendOtp( $this->current_otp );
		$i->amLoggedIn( self::VALID_USERNAME );
		$i->seeCurrentUrlEquals( $redirect_to );
	}

	/**
	 * Attempt to log in with an invalid OTP.
	 *
	 * Conditions:
	 *     2FA status is:         Enabled
	 *     Username/password are: Valid
	 *     OTP is:                Invalid
	 *     Nonce is:              Valid
	 *
	 * Action:           Send a valid username/password.
	 * Expected results: The user is redirected to the 2FA token prompt screen.
	 *                   The user is not logged in yet.
	 * Action:           Enter an invalid OTP.
	 * Expected results: The user is redirected back to wp-login.php
	 *                   The user is not logged in.
	 *                   The user sees an error message.
	 *
	 * @group 2fa_enabled
	 * @group valid_username_password
	 * @group invalid_otp
	 * @group valid_nonce
	 *
	 * @param WebGuy   $i
	 * @param Scenario $scenario
	 */
	public function login_with_invalid_otp( WebGuy $i, Scenario $scenario ) {
		$i->wantTo( 'Log in with an invalid OTP.' );

		$i->enable2fa( self::VALID_USER_ID );
		$i->login( self::VALID_USERNAME, self::VALID_PASSWORD );
		$i->amNotLoggedIn( self::VALID_USERNAME );
		$i->sendOtp( self::INVALID_OTP );
		$i->amNotLoggedIn( self::VALID_USERNAME );
		$i->see( 'The Google Authenticator code is incorrect or has expired.', '#login_error' );
	}

	/**
	 * Attempt to bypass the username/password form by visiting the 2FA form directly with an invalid nonce.
	 *
	 * Conditions:
	 *     2FA status is:         Enabled
	 *     Username/password are: N/A
	 *     OTP is:                Valid
	 *     Nonce is:              Invalid
	 *
	 * Action:           Visit the 2FA form directly with an invalid nonce.
	 *                   Send a valid OTP.
	 * Expected results: The user is not logged in.
	 *                   The user is redirected to the username/password form.
	 *                   The user sees an error message.
	 *
	 * @group 2fa_enabled
	 * @group valid_otp
	 * @group invalid_nonce
	 * @group bypass_username_password
	 *
	 * @param WebGuy   $i
	 * @param Scenario $scenario
	 */
	public function visit_2fa_form_directly_invalid_nonce( WebGuy $i, Scenario $scenario ) {
		$i->wantTo( 'Bypass the username/password form by visiting the 2FA form directly with an invalid nonce.' );

		$i->enable2fa( self::VALID_USER_ID );
		$i->amOnPage( sprintf( '/wp-login.php?action=gapup_token&user_id=%s&gapup_login_nonce=%s', self::VALID_USER_ID, self::INVALID_NONCE ) );
		$this->getCurrentOtp( $i, $scenario, self::VALID_USERNAME );
		$i->sendOtp( $this->current_otp );
		$i->amNotLoggedIn( self::VALID_USERNAME );
		$i->see( 'Your login nonce has expired. Please log in again.', '#login_error' );
	}

	/**
	 * Attempt to bypass the username/password form by visiting the 2FA form directly without any nonce.
	 *
	 * Conditions:
	 *     2FA status is:                  Enabled
	 *     Application password status is: Disabled
	 *     Username/password are:          N/A
	 *     OTP is:                         N/A
	 *     Nonce is:                       Invalid
	 *
	 * Action:           Visit the 2FA form directly without any nonce.
	 * Expected results: The user is not logged in.
	 *                   The user is shown the username/password form.
	 *
	 * @group 2fa_enabled
	 * @group invalid_nonce
	 * @group bypass_username_password
	 *
	 * @param WebGuy   $i
	 * @param Scenario $scenario
	 */
	public function visit_2fa_form_directly_missing_nonce( WebGuy $i, Scenario $scenario ) {
		$i->wantTo( 'Bypass the username/password form by visiting the 2FA form directly without any nonce.' );

		$i->enable2fa( self::VALID_USER_ID );
		$i->amOnPage( sprintf( '/wp-login.php?action=gapup_token&user_id=%s', self::VALID_USER_ID ) );
		$i->amNotLoggedIn( self::VALID_USERNAME );
		$i->see( 'Username', '#loginform' );
		$i->see( 'Password', '#loginform' );
		$i->dontSee( 'Google Authenticator code' );
	}

	/**
	 * Attempt to login to the XML-RPC interface with a valid application password.
	 *
	 * Conditions:
	 *     2FA status is:                     Enabled
	 *     Application password status is:    Enabled
	 *     Username/application password are: Valid
	 *     OTP is:                            N/A
	 *     Nonce is:                          N/A
	 *
	 * Action:           Send valid username/application password to the XML-RPC interface.
	 * Expected results: The user is logged in.
	 *
	 * @group 2fa_enabled
	 * @group application_password_enabled
	 * @group valid_username_application_password
	 *
	 * @param WebGuy   $i
	 * @param Scenario $scenario
	 */
	public function login_to_xmlrpc_interface_with_valid_application_password( WebGuy $i, Scenario $scenario ) {
		$i->wantTo( 'Login to the XML-RPC interface with a valid application password.' );

		$i->enable2fa( self::VALID_USER_ID );
		$i->enableApplicationPassword( self::VALID_USER_ID );
		$i->canLogInToXmlRpc( self::VALID_USERNAME, self::VALID_APPLICATION_PASSWORD );
	}

	/**
	 * Attempt to login to the XML-RPC interface with an invalid application password.
	 *
	 * Conditions:
	 *     2FA status is:                     Enabled
	 *     Application password status is:    Enabled
	 *     Username/application password are: Invalid
	 *     OTP is:                            N/A
	 *     Nonce is:                          N/A
	 *
	 * Action:           Send invalid username/application password to the XML-RPC interface.
	 * Expected results: The user is not logged in.
	 *
	 * @group 2fa_enabled
	 * @group application_password_enabled
	 * @group invalid_username_application_password
	 *
	 * @param WebGuy   $i
	 * @param Scenario $scenario
	 */
	public function login_to_xmlrpc_interface_with_invalid_application_password( WebGuy $i, Scenario $scenario ) {
		$i->wantTo( 'Login to the XML-RPC interface with an invalid application password.' );

		$i->enable2fa( self::VALID_USER_ID );
		$i->enableApplicationPassword( self::VALID_USER_ID );
		$i->cantLogInToXmlRpc( self::VALID_USERNAME, self::INVALID_APPLICATION_PASSWORD );
	}

	/**
	 * Attempt to login to the XML-RPC interface when application passwords are disabled.
	 *
	 * Conditions:
	 *     2FA status is:                     Enabled
	 *     Application password status is:    Disabled
	 *     Username/application password are: Valid
	 *     OTP is:                            N/A
	 *     Nonce is:                          N/A
	 *
	 * Action:           Send valid username/application password to the XML-RPC interface.
	 * Expected results: The user is not logged in.
	 *
	 * @group 2fa_enabled
	 * @group application_password_disabled
	 * @group valid_username_application_password
	 *
	 * @param WebGuy   $i
	 * @param Scenario $scenario
	 */
	public function login_to_xmlrpc_interface_when_application_passwords_disabled( WebGuy $i, Scenario $scenario ) {
		$i->wantTo( 'Login to the XML-RPC interface when application passwords are disabled.' );

		$i->enable2fa( self::VALID_USER_ID );
		$i->cantLogInToXmlRpc( self::VALID_USERNAME, self::VALID_APPLICATION_PASSWORD );
	}

	/**
	 * Attempt to login to the web interface with a valid application password.
	 *
	 * Conditions:
	 *     2FA status is:                     Enabled
	 *     Application password status is:    Enabled
	 *     Username/application password are: Valid
	 *     OTP is:                            N/A
	 *     Nonce is:                          N/A
	 *
	 * Action:           Send valid username/application password to the web interface.
	 * Expected results: The user is not logged in.
	 *                   The user is redirected to the username/password form.
	 *
	 * @group 2fa_enabled
	 * @group application_password_enabled
	 * @group valid_username_application_password
	 *
	 * @param WebGuy   $i
	 * @param Scenario $scenario
	 */
	public function login_to_web_interface_with_valid_application_password( WebGuy $i, Scenario $scenario ) {
		$i->wantTo( 'Login to the web interface with a valid application password.' );

		$i->enable2fa( self::VALID_USER_ID );
		$i->enableApplicationPassword( self::VALID_USER_ID );
		$i->login( self::VALID_USERNAME, self::VALID_APPLICATION_PASSWORD );
		$i->amNotLoggedIn( self::VALID_USERNAME );
		$i->see( 'The password you entered for the username '. self::VALID_USERNAME .' is incorrect.', '#login_error' );
	}

	/**
	 * Attempt to login to the web interface with an invalid application password.
	 *
	 * Conditions:
	 *     2FA status is:                     Enabled
	 *     Application password status is:    Enabled
	 *     Username/application password are: Invalid
	 *     OTP is:                            N/A
	 *     Nonce is:                          N/A
	 *
	 * Action:           Send invalid username/application password to the web interface.
	 * Expected results: The user is not logged in.
	 *                   The user is redirected to the username/password form.
	 *
	 * @group 2fa_enabled
	 * @group application_password_enabled
	 * @group invalid_username_application_password
	 *
	 * @param WebGuy   $i
	 * @param Scenario $scenario
	 */
	public function login_to_web_interface_with_invalid_application_password( WebGuy $i, Scenario $scenario ) {
		$i->wantTo( 'Login to the web interface with an invalid application password.' );

		$i->enable2fa( self::VALID_USER_ID );
		$i->enableApplicationPassword( self::VALID_USER_ID );
		$i->login( self::VALID_USERNAME, self::INVALID_APPLICATION_PASSWORD );
		$i->amNotLoggedIn( self::VALID_USERNAME );
		$i->see( 'The password you entered for the username '. self::VALID_USERNAME .' is incorrect.', '#login_error' );
	}

	/*
	 * @todo
	 * Cases to add:
	 *
	 * User A enters correct token, then User B enters same token   => User A logged in, user B Redirected to 2FA form, shown error
	 */
}
