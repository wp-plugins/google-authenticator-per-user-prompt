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
	const TABLE_PREFIX                 = 'wp_';
	const REGEX_AUTH_COOKIE            = '/(wordpress_)([0-9a-zA-Z]){32}/';
	const REGEX_LOGGED_IN_COOKIE       = '/(wordpress_logged_in_)([0-9a-zA-Z]){32}/';

	/**
	 * Prompt the tester for a valid one time password
	 *
	 * Because of the nature of one-time passwords, the test suite can't be fully automated.
	 * We need to collect a valid token before each test.
	 *
	 * Codeception runs each test twice, so we avoid prompting during the analysis phase,
	 * because the analyzer would never input anything.
	 *
	 * @param WebGuy   $i
	 * @param Scenario $scenario
	 * @param string   $username
	 */
	protected function prompt_for_valid_otp( $i, Scenario $scenario, $username ) {
		if ( ! $scenario->running() ) {
			return;
		}

		$this->valid_otp = readline( sprintf( "\nEnter the current OTP for %s: ", $username ) );
	}

	/**
	 * Send a login request with the given username and password.
	 *
	 * @param WebGuy $i
	 * @param string $username
	 * @param string $password
	 * @param string $redirect_to
	 */
	protected function login( WebGuy $i, $username, $password, $redirect_to = false ) {
		$url = '/wp-login.php';
		if ( $redirect_to ) {
			$url .= '?redirect_to=' . $redirect_to;
		}

		$i->amOnPage( $url );
		$i->fillField( 'user_login', $username );
		$i->fillField( 'user_pass', $password );
		$i->click( '#wp-submit' );
	}

	/**
	 * Asserts whether the given user is logged in or not.
	 *
	 * @param WebGuy $i
	 * @param string $username
	 * @param bool   $expect_logged_in
	 */
	protected function assert_user_is_logged_in( WebGuy $i, $username, $expect_logged_in ) {
		// todo this should be a helper, because it makes assertions?
			// maybe all of these protected methods should be helpers, so that this file only has the actual tests?

		if ( $expect_logged_in ) {
			$i->see( 'Howdy, ' . $username, '#wp-admin-bar-my-account' );
			// $i->seeCurrentUrlMatches( '^\/wp-admin/' );    // make sure it starts with /wp-admin, but still allow * after that (e.g., ?redirect_to=[foo])		// todo
			$i->seeCookieMatches( self::REGEX_AUTH_COOKIE );
			$i->seeCookieMatches( self::REGEX_LOGGED_IN_COOKIE );
		} else {
			$i->dontSee( 'Howdy, ' . $username, '#wp-admin-bar-my-account' );
			$i->seeInCurrentUrl( '/wp-login.php' );
			$i->dontSeeInCurrentUrl( '/wp-admin/' );
			$i->dontSeeCookieMatches( self::REGEX_AUTH_COOKIE );
			$i->dontSeeCookieMatches( self::REGEX_LOGGED_IN_COOKIE );
		}
	}

	/**
	 * Send a one-time password to the 2FA token prompt.
	 *
	 * @param WebGuy $i
	 * @param string $otp
	 */
	protected function send_otp( WebGuy $i, $otp ) {
		$i->see( 'Google Authenticator code' );
		$i->seeInCurrentUrl( 'action=gapup_token' );
		$i->fillField( 'user_email', $otp );	// [sic], Google Authenticator uses the e-mail field instead of one named more appropriately
		$i->click( '#gapup_token_prompt' );
	}

	/**
	 * Enable Google Authenticator for the given account.
	 *
	 * @param WebGuy $i
	 * @param int    $user_id
	 */
	protected function enable_2fa( WebGuy $i, $user_id ) {
		$i->haveInDatabase(
			self::TABLE_PREFIX . 'usermeta',
			array(
				'user_id'    => $user_id,
				'meta_key'   => 'googleauthenticator_enabled',
				'meta_value' => 'enabled'
			)
		);
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

		$this->login( $i, self::VALID_USERNAME, self::INVALID_PASSWORD );
		$i->see( sprintf( 'The password you entered for the username %s is incorrect.', self::VALID_USERNAME ), '#login_error' );
		$i->seeInCurrentUrl( 'wp-login.php' );
		$this->assert_user_is_logged_in( $i, self::VALID_USERNAME, false );

		$this->login( $i, self::INVALID_USERNAME, self::VALID_PASSWORD );
		$i->see( 'Invalid username.', '#login_error' );
		$i->seeInCurrentUrl( 'wp-login.php' );
		$this->assert_user_is_logged_in( $i, self::INVALID_USERNAME, false );
		
		$this->login( $i, self::INVALID_USERNAME, self::INVALID_PASSWORD );
		$i->see( 'Invalid username.', '#login_error' );
		$i->seeInCurrentUrl( 'wp-login.php' );
		$this->assert_user_is_logged_in( $i, self::INVALID_USERNAME, false );
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

		$this->login( $i, self::VALID_USERNAME, self::VALID_PASSWORD );
		$this->assert_user_is_logged_in( $i, self::VALID_USERNAME, true );
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

		$this->enable_2fa( $i, self::VALID_USER_ID );
		$this->login( $i, self::VALID_USERNAME, self::VALID_PASSWORD );
		$this->assert_user_is_logged_in( $i, self::VALID_USERNAME, false );
		$this->prompt_for_valid_otp( $i, $scenario, self::VALID_USERNAME );
		$this->send_otp( $i, $this->valid_otp );
		$this->assert_user_is_logged_in( $i, self::VALID_USERNAME, true );
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

		$this->enable_2fa( $i, self::VALID_USER_ID );
		$this->login( $i, self::VALID_USERNAME, self::VALID_PASSWORD );
		$this->assert_user_is_logged_in( $i, self::VALID_USERNAME, false );

		$this->prompt_for_valid_otp( $i, $scenario, self::VALID_USERNAME );
		if ( $scenario->running() ) {
			sleep( 60 * 3 + 1 );
		}
		$this->send_otp( $i, $this->valid_otp );

		$this->assert_user_is_logged_in( $i, self::VALID_USERNAME, false );
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

		$this->enable_2fa( $i, self::VALID_USER_ID );
		$this->login( $i, self::VALID_USERNAME, self::VALID_PASSWORD, $redirect_to );
		$this->assert_user_is_logged_in( $i, self::VALID_USERNAME, false );
		$this->prompt_for_valid_otp( $i, $scenario, self::VALID_USERNAME );
		$this->send_otp( $i, $this->valid_otp );
		$this->assert_user_is_logged_in( $i, self::VALID_USERNAME, true );
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
