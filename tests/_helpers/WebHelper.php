<?php
namespace Codeception\Module;

use Guzzle\Common\Exception\ExceptionCollection;
use Guzzle\Http\Message;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\DependencyInjection\SimpleXMLElement;

class WebHelper extends \Codeception\Module {
	const TABLE_PREFIX           = 'wp_';
	const REGEX_AUTH_COOKIE      = '/(wordpress_)([0-9a-zA-Z]){32}/';
	const REGEX_LOGGED_IN_COOKIE = '/(wordpress_logged_in_)([0-9a-zA-Z]){32}/';

	/**
	 * Send a login request with the given username and password.
	 *
	 * @param string $username
	 * @param string $password
	 * @param string $redirect_to
	 */
	public function login( $username, $password, $redirect_to = false ) {
		/** @var $i PhpBrowser */
		$i = $this->getModule( 'PhpBrowser' );

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
		$i->fillField( 'user_email', $otp );	// [sic], Google Authenticator uses the e-mail field instead of one named more appropriately
		$i->click( '#gapup_token_prompt' );
	}

	/**
	 * Match a regular expression pattern in a cookie name.
	 *
	 * @param string $pattern
	 * @return bool
	 */
	protected function cookieMatches( $pattern ) {
		/** @var $cookie Cookie */

		$found   = false;
		$cookies = $this->getModule( 'PhpBrowser' )->session->getDriver()->getClient()->getCookieJar()->all();

		$this->debug( print_r( $cookies, true ) );

		foreach( $cookies as $cookie ) {
			if ( preg_match( $pattern, $cookie->getName() ) ) {
				$found = true;
				break;
			}
		}

		return $found;
	}

	/**
	 * Asserts that a cookie matching $pattern exists.
	 *
	 * @param string $pattern
	 */
	public function seeCookieMatches( $pattern ) {
		$this->assertTrue( $this->cookieMatches( $pattern ) );
	}

	/**
	 * Asserts that no cookies matching $pattern exist.
	 *
	 * @param string $pattern
	 */
	public function dontSeeCookieMatches( $pattern ) {
		$this->assertFalse( $this->cookieMatches( $pattern ) );
	}

	/**
	 * Asserts that the user is logged in.
	 *
	 * @param string $username
	 */
	public function amLoggedIn( $username ) {
		/** @var $i PhpBrowser */
		$i = $this->getModule( 'PhpBrowser' );

		$this->assertTrue( $this->cookieMatches( self::REGEX_AUTH_COOKIE ) );
		$this->assertTrue( $this->cookieMatches( self::REGEX_LOGGED_IN_COOKIE ) );

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

		$this->assertFalse( $this->cookieMatches( self::REGEX_AUTH_COOKIE ) );
		$this->assertFalse( $this->cookieMatches( self::REGEX_LOGGED_IN_COOKIE ) );

		$i->dontSee( 'Howdy, ' . $username, '#wp-admin-bar-my-account' );
		$i->seeInCurrentUrl( '/wp-login.php' );
		$i->dontSeeInCurrentUrl( '/wp-admin/' );	// todo redirect_to fail? is this even needed?
	}

	/**
	 * Asserts that the user can log into the XML-RPC interface with the given username and password.
	 *
	 * @param string $username
	 * @param string $password
	 */
	public function canLogInToXmlRpc( $username, $password ) {
		$this->assertTrue( $this->loginXmlRpc( $username, $password ) );
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

		$response      = $this->sendHttpRequest( 'POST', 'xmlrpc.php', array(), $request_body, array() );
		$response_body = $response->getBody( true );

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
	 * @param array  $headers
	 * @param string $body
	 * @param array  $options
	 * @return Message\Response $response
	 */
	protected function sendHttpRequest( $method, $url, $headers, $body, $options ) {
		/** @var $i        PhpBrowser */
		/** @var $response Message\Response */

		$i   = $this->getModule( 'PhpBrowser' );
		$url = $i->_getUrl() . $url;

		$response = $i->executeInGuzzle(
			function( \Guzzle\Http\Client $client ) use ( $method, $url, $headers, $body, $options ) {
				return $client->send( $client->createRequest( $method, $url, $headers, $body, $options ) );
			}
		);

		$this->debug( $response->getRawHeaders() );
		$this->debug( $response->getBody( true ) );

		return $response;
	}
}
