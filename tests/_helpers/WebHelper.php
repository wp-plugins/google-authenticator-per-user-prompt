<?php
namespace Codeception\Module;

class WebHelper extends \Codeception\Module {

	/**
	 * Match a regular expression pattern in a cookie name.
	 *
	 * @param string $pattern
	 * @return bool
	 */
	protected function cookieMatches( $pattern ) {
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
	 * Asserts that a cookie matching $pattern exists
	 *
	 * @param string $pattern
	 */
	public function seeCookieMatches( $pattern ) {
		$this->assertTrue( $this->cookieMatches( $pattern ) );
	}

	/**
	 * Asserts that no cookies matching $pattern exist
	 *
	 * @param string $pattern
	 */
	public function dontSeeCookieMatches( $pattern ) {
		$this->assertFalse( $this->cookieMatches( $pattern ) );
	}
}
