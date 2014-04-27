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
	 */
	protected function login( WebGuy $i, $username, $password ) {
		$i->amOnPage( '/wp-login.php' );
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
			$i->seeCurrentUrlEquals( '/wp-admin/' );
			$i->seeCookie( 'wordpress_49d4ab732056d505c2c751e2f7a5d842' );				// todo how to get hash programatically?
			$i->seeCookie( 'wordpress_logged_in_49d4ab732056d505c2c751e2f7a5d842' );	// todo also need to validate hash some how? maybe not
		} else {
			$i->dontSee( 'Howdy, ' . $username, '#wp-admin-bar-my-account' );
			$i->seeInCurrentUrl( '/wp-login.php' );
			$i->dontSeeInCurrentUrl( '/wp-admin/' );
			$i->dontSeeCookie( 'wordpress_*' );				// todo don't care what hash value is, shouldn't have any wordpress_* cookies set
			$i->dontSeeCookie( 'wordpress_logged_in_*' );	// todo
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
	 * @param WebGuy   $i
	 * @param Scenario $scenario
	 * @param int      $user_id
	 */
	protected function enable_2fa( WebGuy $i, Scenario $scenario, $user_id ) {
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
	 * Action:           Log in with invalid credentials
	 * Expected results: The user is redirected back to wp-login.php.
	 *                   The user is shown an error.
	 *                   The user is not logged in.
	 *
	 * @group invalid_credentials
	 *
	 * @param WebGuy   $i
	 * $param Scenario $scenario
	 */
	public function login_invalid_credentials( WebGuy $i, Scenario $scenario ) {
		$i->wantTo( 'Log in with invalid credentials.' );

		$this->login( $i, self::VALID_USERNAME, self::INVALID_PASSWORD );
		$i->see( sprintf( 'The password you entered for the username %s is incorrect', self::VALID_USERNAME ), '#login_error' );
		$i->seeInCurrentUrl( 'wp-login.php' );
		$this->assert_user_is_logged_in( $i, self::VALID_USERNAME, false );

		$this->login( $i, self::INVALID_USERNAME, self::VALID_PASSWORD );
		$i->see( 'Invalid username', '#login_error' );
		$i->seeInCurrentUrl( 'wp-login.php' );
		$this->assert_user_is_logged_in( $i, self::INVALID_USERNAME, false );
		
		$this->login( $i, self::INVALID_USERNAME, self::INVALID_PASSWORD );
		$i->see( 'Invalid username', '#login_error' );
		$i->seeInCurrentUrl( 'wp-login.php' );
		$this->assert_user_is_logged_in( $i, self::INVALID_USERNAME, false );
	}
	
	/**
	 * Action:           Log in with valid credentials when the account has 2FA disabled.
	 * Expected results: The user is not redirected to the 2FA token prompt screen.
	 *                   The user is logged in.
	 *
	 * @group 2fa_disabled
	 * @group valid_credentials
	 *
	 * @param WebGuy   $i
	 * $param Scenario $scenario
	 */
	public function login_2fa_disabled_valid_credentials( WebGuy $i, Scenario $scenario ) {
		$i->wantTo( 'Log in with valid credentials when the account has 2FA disabled.' );

		$this->login( $i, self::VALID_USERNAME, self::VALID_PASSWORD );
		$this->assert_user_is_logged_in( $i, self::VALID_USERNAME, true );
	}

	/**
	 * Action:           Log in with valid credentials when the account has 2FA enabled.
	 * Expected results: The user is redirected to the 2FA token prompt screen.
	 *                   The user is not logged in yet.
	 * Action:           Enter a valid OTP.
	 * Expected results: The user is logged in.
	 *
	 * @group 2fa_enabled
	 * @group valid_credentials
	 * @group valid_otp
	 *
	 * @param WebGuy   $i
	 * @param Scenario $scenario
	 */
	public function login_2fa_enabled_valid_credentials_valid_otp( WebGuy $i, Scenario $scenario ) {
		$i->wantTo( 'Log in with valid credentials when the account has 2FA enabled.' );

		$this->enable_2fa( $i, $scenario, self::VALID_USER_ID );
		$this->login( $i, self::VALID_USERNAME, self::VALID_PASSWORD );
		$this->assert_user_is_logged_in( $i, self::VALID_USERNAME, false );
		$this->prompt_for_valid_otp( $i, $scenario, self::VALID_USERNAME );
		$this->send_otp( $i, $this->valid_otp );
		$this->assert_user_is_logged_in( $i, self::VALID_USERNAME, true );
	}

	/*
	 * Cases to add:
	 *
	 * User enters correct username/password, but nonce expires   => Redirected to login
	 * User enters correct 2FA token                              => logged in, redirect to original redirect_to parameter
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
