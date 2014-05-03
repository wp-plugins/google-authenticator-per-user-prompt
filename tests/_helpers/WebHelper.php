<?php
namespace Codeception\Module;

use Guzzle\Common\Exception\ExceptionCollection;
use Symfony\Component\BrowserKit\Cookie;

class WebHelper extends \Codeception\Module {
	const REGEX_AUTH_COOKIE      = '/(wordpress_)([0-9a-zA-Z]){32}/';
	const REGEX_LOGGED_IN_COOKIE = '/(wordpress_logged_in_)([0-9a-zA-Z]){32}/';

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
}
