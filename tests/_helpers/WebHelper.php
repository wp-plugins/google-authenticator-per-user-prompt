<?php
namespace Codeception\Module;

use GuzzleHttp\Message;
use Symfony\Component\BrowserKit\Cookie;

class WebHelper extends \Codeception\Module {
	const TABLE_PREFIX           = 'wp_';
	const REGEX_AUTH_COOKIE      = '/(wordpress_)([0-9a-zA-Z]){32}/';
	const REGEX_LOGGED_IN_COOKIE = '/(wordpress_logged_in_)([0-9a-zA-Z]){32}/';

	/**
	 * Initialization
	 *
	 * Runs after Codeception configuration is loaded.
	 */
	public function _initialize() {
		require_once( __DIR__ . '/GAPUPAT_One_Time_Passwords.php' );
	}

	/**
	 * Disables the Google Authenticator plugin.
	 */
	public function disableGoogleAuthenticator() {
		$this->getModule( 'Db' )->dbh->exec("
			UPDATE ". self::TABLE_PREFIX ."options
			SET option_value = 'a:1:{i:0;s:50:\"google-authenticator-per-user-prompt/bootstrap.php\";}'
			WHERE option_id = 37
		");
	}

	/**
	 * Send a login request with the given username and password.
	 *
	 * @param string $username
	 * @param string $password
	 * @param bool   $redirect_to
	 * @param bool   $remember_me
	 */
	public function login( $username, $password, $redirect_to = false, $remember_me = false ) {
		/** @var $i PhpBrowser */
		$i = $this->getModule( 'PhpBrowser' );

		$url = '/wp-login.php';
		if ( $redirect_to ) {
			$url .= '?redirect_to=' . $redirect_to;
		}

		$i->amOnPage( $url );
		$i->fillField( array( 'id' => 'user_login' ), $username );
		$i->fillField( array( 'id' => 'user_pass' ),  $password );

		if ( $remember_me ) {
			$i->checkOption( '#rememberme' );
		}

		$i->click( '#wp-submit' );
	}

	/**
	 * Enable Google Authenticator for the given account.
	 *
	 * @param int $user_id
	 */
	public function enable2fa( $user_id ) {
		/** @var $i Db */
		$i = $this->getModule( 'Db' );

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
	 * Enable the Application Password setting for the given account.
	 *
	 * @param int $user_id
	 */
	public function enableApplicationPassword( $user_id ) {
		/** @var $i Db */
		$i = $this->getModule( 'Db' );

		$i->haveInDatabase(
			self::TABLE_PREFIX . 'usermeta',
			array(
				'user_id'    => $user_id,
				'meta_key'   => 'googleauthenticator_pwdenabled',
				'meta_value' => 'enabled'
			)
		);
	}

	/**
	 * Send a one-time password to the 2FA token prompt.
	 *
	 * @param string $otp
	 */
	public function sendOtp( $otp ) {
		/** @var $i PhpBrowser */
		$i = $this->getModule( 'PhpBrowser' );

		$i->see( 'Google Authenticator code' );
		$i->seeInCurrentUrl( 'action=gapup_token' );
		$i->fillField( array( 'id' => 'user_email' ), $otp );	// [sic], Google Authenticator uses the e-mail field instead of one named more appropriately
		$i->click( '#gapup_token_prompt' );
	}

	/**
	 * Determine if any cookie exists whose name matches the given regular expression.
	 *
	 * @param string $pattern
	 * @return bool
	 */
	protected function anyCookieMatches( $pattern ) {
		/** @var $cookie Cookie */

		$found   = false;
		$cookies = $this->getModule( 'PhpBrowser' )->client->getCookieJar()->all();

		$this->debug( print_r( $cookies, true ) );

		foreach( $cookies as $cookie ) {
			if ( $this->cookieMatches( $cookie, $pattern ) ) {
				$found = true;
				break;
			}
		}

		return $found;
	}

	/**
	 * Match a regular expression pattern in a cookie name.
	 *
	 * @param Cookie $cookie
	 * @param string $pattern
	 * @return bool
	 */
	protected function cookieMatches( $cookie, $pattern ) {
		return preg_match( $pattern, $cookie->getName() );
	}

	/**
	 * Asserts that a cookie matching $pattern exists.
	 *
	 * @param string $pattern
	 */
	public function seeCookieMatches( $pattern ) {
		$this->assertTrue( $this->anyCookieMatches( $pattern ) );
	}

	/**
	 * Asserts that no cookies matching $pattern exist.
	 *
	 * @param string $pattern
	 */
	public function dontSeeCookieMatches( $pattern ) {
		$this->assertFalse( $this->anyCookieMatches( $pattern ) );
	}

	/**
	 * Asserts that the given user's WordPress authorization cookie expires in the given number of days.
	 *
	 * @param int $user_id
	 * @param int $expected_days
	 */
	public function seeAuthCookieExpiresInDays( $user_id, $expected_days ) {
		/** @var $auth_cookie Cookie */

		$auth_cookie = $this->getAuthCookie();
		\PHPUnit_Framework_Assert::assertInstanceOf( 'Symfony\Component\BrowserKit\Cookie', $auth_cookie );

		$expiration  = $this->getAuthCookieExpiration( $auth_cookie );
		$actual_days = round( ( $expiration - time() ) / 60 / 60 / 24 );

		$this->assertEquals( $actual_days, $expected_days );
	}

	/**
	 * Pluck the WordPress authorization cookie from the cookie jar.
	 *
	 * @return Cookie | false
	 */
	protected function getAuthCookie() {
		/** @var $cookie Cookie */

		$found   = false;
		$cookies = $this->getModule( 'PhpBrowser' )->client->getCookieJar()->all();

		foreach ( $cookies as $cookie ) {
			if ( $this->cookieMatches( $cookie, self::REGEX_AUTH_COOKIE ) ) {
				$found = true;
				break;
			}
		}

		if ( $found ) {
			return $cookie;
		} else {
			return false;
		}
	}

	/**
	 * Extract the expiration value from a WordPress authorization cookie.
	 *
	 * Note that this is the internal expiration timestamp that WordPress stores in the cookie value, not the
	 * expiration timestamp in the cookie's Expires field.
	 *
	 * @param Cookie $auth_cookie
	 * @return int | false
	 */
	protected function getAuthCookieExpiration( $auth_cookie ) {
		$expiration = $auth_cookie->getValue();
		$expiration = explode( '|', $expiration );

		if ( isset( $expiration[1] ) ) {
			$expiration = $expiration[1];
		}

		if ( is_numeric( $expiration ) && $expiration > 1 ) {
			return $expiration;
		} else {
			return false;
		}
	}

	/**
	 * Asserts that the user is logged in.
	 *
	 * @param string $username
	 */
	public function amLoggedIn( $username ) {
		/** @var $i PhpBrowser */
		$i = $this->getModule( 'PhpBrowser' );

		$this->assertTrue( $this->anyCookieMatches( self::REGEX_AUTH_COOKIE ) );
		$this->assertTrue( $this->anyCookieMatches( self::REGEX_LOGGED_IN_COOKIE ) );

		$i->see( 'Howdy, ' . $username, '#wp-admin-bar-my-account' );
		$i->seeInCurrentUrl( '/wp-admin/' );
		$i->dontSeeInCurrentUrl( '/wp-login.php' );
	}

	/**
	 * Asserts that the user is not logged in.
	 *
	 * @param string $username
	 */
	public function amNotLoggedIn( $username ) {
		/** @var $i PhpBrowser */
		$i = $this->getModule( 'PhpBrowser' );

		$this->assertFalse( $this->anyCookieMatches( self::REGEX_AUTH_COOKIE ) );
		$this->assertFalse( $this->anyCookieMatches( self::REGEX_LOGGED_IN_COOKIE ) );

		$i->dontSee( 'Howdy, ' . $username, '#wp-admin-bar-my-account' );
		$i->seeInCurrentUrl( '/wp-login.php' );
		$i->dontSeeInCurrentUrl( '/wp-admin/' );
	}

	/**
	 * Asserts that the user can login to the XML-RPC interface with the given username and password.
	 *
	 * @param string $username
	 * @param string $password
	 */
	public function canLogInToXmlRpc( $username, $password ) {
		$this->assertTrue( $this->loginXmlRpc( $username, $password ) );
	}

	/**
	 * Asserts that the user can't login to the XML-RPC interface with the given username and password.
	 *
	 * @param string $username
	 * @param string $password
	 */
	public function cantLogInToXmlRpc( $username, $password ) {
		$this->assertFalse( $this->loginXmlRpc( $username, $password ) );
	}

	/**
	 * Attempts to login to the XML-RPC interface with the given username and password.
	 *
	 * Since logging-in is built into every XML-RPC request that requires authentication rather than being done
	 * once at the beginning of a session, we use the wp.getUsersBlogs method as a test of whether or not the
	 * login was successful.
	 *
	 * @param string $username
	 * @param string $password
	 * @return bool
	 */
	protected function loginXmlRpc( $username, $password ) {
		/** @var $response Message\Response */
		$logged_in = true;

		$request_body = sprintf( '
			<?xml version="1.0" ?>
			<methodCall>
				<methodName>wp.getUsersBlogs</methodName>
				<params>
					<param>
						<value>%s</value>
					</param>

					 <param>
						 <value>%s</value>
					</param>
				</params>
			</methodCall>',
			$username,
			$password
		);

		$expected_response_strings = array(
			'<name>isAdmin</name><value><boolean>0</boolean></value>',
			'<name>blogName</name><value><string>General WordPress Sandbox</string></value>',
		);

		$response      = $this->sendHttpRequest( 'POST', 'xmlrpc.php', array( 'body' => $request_body ) );
		$response_body = $response->getBody()->getContents();

		$this->debug( '$response_body: '. $response_body );

		foreach ( $expected_response_strings as $needle ) {
			if ( false === strpos( $response_body, $needle ) ) {
				$logged_in = false;
			}
		}

		return $logged_in;
	}

	/**
	 * Send an HTTP request and return the response.
	 *
	 * @param string $method GET | POST
	 * @param string $url
	 * @param array  $options
	 * @return Message\Response $response
	 */
	protected function sendHttpRequest( $method, $url, $options ) {
		/** @var $i        PhpBrowser */
		/** @var $response Message\Response */

		$i   = $this->getModule( 'PhpBrowser' );
		$url = $i->_getUrl() . $url;

		$response = $i->executeInGuzzle(
			function( \GuzzleHttp\Client $client ) use ( $method, $url, $options ) {
				return $client->send( $client->createRequest( $method, $url, $options ) );
			}
		);

		$this->debug( print_r( $response, true ) );

		return $response;
	}
}
