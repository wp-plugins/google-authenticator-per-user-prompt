<?php

/**
 * Generate Time-based One Time Passwords
 *
 * This should really be in WebHelper.php, but I don't think Codeception provides a way to return a value to the
 * suite from the helper.
 *
 * Modified from https://www.idontplaydarts.com/2011/07/google-totp-two-factor-authentication-for-php/
 */
class GAPUPAT_One_Time_Passwords {

	/**
	 * Generate the current OTP.
	 *
	 * @param string $secret       The Base 32 encoded secret
	 * @param int    $otp_lifetime The length of time in seconds before a new OTP expires
	 */
	public static function getCurrentOtp( $secret, $otp_lifetime ) {
		require_once( dirname( dirname( dirname( __DIR__ ) ) ) . '/google-authenticator/base32.php' );

		$digits         = 6;
		$secret_binary  = Base32::decode( $secret );
		$timecode       = ( time() * 1000 ) / ( $otp_lifetime * 1000 );
		$counter_binary = pack( 'N*', 0 ) . pack( 'N*', $timecode );
		$hash           = hash_hmac( 'sha1', $counter_binary, $secret_binary, true );
		$otp            = self::oathTruncate( $hash, $digits );

		return str_pad( $otp, $digits, '0', STR_PAD_LEFT );
	}

	/**
	 * Extract the OTP from the SHA1 hash.
	 *
	 * @param string $hash
	 * @param int    $digits
	 * @return int
	 */
	protected static function oathTruncate( $hash, $digits ) {
		$offset = ord( $hash[19] ) & 0xf;

		$code = (
			( ( ord( $hash[ $offset + 0 ] ) & 0x7f ) << 24 ) |
			( ( ord( $hash[ $offset + 1 ] ) & 0xff ) << 16 ) |
			( ( ord( $hash[ $offset + 2 ] ) & 0xff ) << 8 ) |
			( ord( $hash[ $offset + 3 ] ) & 0xff )
		);

		return $code % pow( 10, $digits );
	}
}
