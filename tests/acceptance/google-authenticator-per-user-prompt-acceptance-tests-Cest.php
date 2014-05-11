<?php

use \WebGuy;
use \Codeception\Scenario;
use \PHPUnit_Framework_Assert;

class Google_Authenticator_Per_User_Prompt_Acceptance_Tests {
	protected $valid_otp;

	const VALID_USER_ID                = 2;
	const VALID_USERNAME               = '2fa-tester';
	const VALID_PASSWORD               = 'password';
	const VALID_APPLICATION_PASSWORD   = 'YUKP HO6T Z5WB QW3N';
	const INVALID_USERNAME             = 'fake-user';
	const INVALID_PASSWORD             = 'fake-password';
	const INVALID_OTP                  = '000000';
	const INVALID_NONCE                = '00000000000000000000000000000000';
	const INVALID_APPLICATION_PASSWORD = 'fake-password';
	const OTP_LIFETIME                 = 61;
	const NONCE_LIFETIME               = 26;	// see ../tests/readme.txt

	/**
	 * Prompt the tester for a valid one time password
	 *
	 * Because of the nature of one-time passwords, the test suite can't be fully automated.
	 * We need to collect a valid token before each test.
	 *
	 * Codeception runs each test twice, so we avoid prompting during the analysis phase,
	 * because the analyzer would never input anything.
	 *
	 * @todo This should probably be a helper too, but I'm not sure there'd be a way to access the
	 * value. It wouldn't be returned, and codeception uses call_user_func(), which won't pass
	 * by reference.
	 *
	 * @param WebGuy   $i
	 * @param Scenario $scenario
	 * @param string   $username
	 */
	protected function enterValidOtp( WebGuy $i, Scenario $scenario, $username ) {
		if ( ! $scenario->running() ) {
			return;
		}

		$this->valid_otp = readline( sprintf( "\nEnter the current OTP for %s: ", $username ) );
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
		$this->enterValidOtp( $i, $scenario, self::VALID_USERNAME );
		$i->sendOtp( $this->valid_otp );
		$i->amLoggedIn( self::VALID_USERNAME );
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

		$this->enterValidOtp( $i, $scenario, self::VALID_USERNAME );
		if ( $scenario->running() ) {
			sleep( self::NONCE_LIFETIME );
		}
		$i->sendOtp( $this->valid_otp );

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

		$this->enterValidOtp( $i, $scenario, self::VALID_USERNAME );
		if ( $scenario->running() ) {
			sleep( self::OTP_LIFETIME );
		}
		$i->sendOtp( $this->valid_otp );

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
		$this->enterValidOtp( $i, $scenario, self::VALID_USERNAME );
		$i->sendOtp( $this->valid_otp );
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
		$this->enterValidOtp( $i, $scenario, self::VALID_USERNAME );
		$i->sendOtp( $this->valid_otp );
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

		// todo may need to revisit this and make sure it's actually testing correctly once get the opposite test setup and working
	}

	/*
	 * @todo
	 * Cases to add:
	 *
	 * Enter valid application password w/ app pwd setting disabled => Not logged in
	 * Login to XML-RPC with invalid username/password              => Not logged in
	 * User A enters correct token, then User B enters same token   => Redirected to 2FA form, shown error
	 * Check 'remember me' box                                      => Gets extra cookie or whatever
	 * Doesn't check 'remember me' box                              => Doesn't get extra cookie or whatever
	 */
}
