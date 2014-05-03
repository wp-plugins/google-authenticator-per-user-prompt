<?php

use \WebGuy;
use \Codeception\Scenario;
use \PHPUnit_Framework_Assert;

class Google_Authenticator_Per_User_Prompt_Acceptance_Tests {
	protected $valid_otp;

	const VALID_USER_ID                = 2;
	const VALID_USERNAME               = '2fa-tester';
	const VALID_PASSWORD               = 'password';
	const VALID_APPLICATION_PASSWORD   = 'application-password';
	const INVALID_USERNAME             = 'fake-user';
	const INVALID_PASSWORD             = 'fake-password';
	const INVALID_OTP                  = '000000';
	const INVALID_APPLICATION_PASSWORD = 'fake-password';

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
			sleep( 26 );    /* requires an mu-plugin callback setup for the gapup_nonce_expiration filter which returns 15 seconds. otherwise would have to wait 3 minutes while running tests */
		}
		$i->sendOtp( $this->valid_otp );

		$i->amNotLoggedIn( self::VALID_USERNAME );
		$i->see( 'Your login nonce has expired. Please log in again.', '#login_error' );
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

	/*
	 * @todo
	 * Cases to add:
	 *
	 * User entered valid 2fa token, but then waited too long to submit and it expired => Redirected to 2FA form, shown error, can login if enter correct code this time
	 * User enters incorrect 2FA token                            => Redirected to 2FA form, shown error, can login if enter correct code this time
	 * User enters correct application password using XMLRPC      => Logged in, bypasses 2FA token
	 * User enters correct application password using web         => Redirected to login form
	 * User visits 2FA form directly                              => Redirected to login screen
	 * User A enters correct token, then User B enters same token => Redirected to 2FA form, shown error
	 *
	 * To check if auth cookies are sent after entering username/password but before entering the 2FA token:
	 * curl -i --data "log=username&pwd=password&wp-submit=Log+In&testcookie=1" --cookie "wordpress_test_cookie=WP+Cookie+check" http://example.org/wp-login.php
	 * webguy probably has a method for that instead
	 */
}
