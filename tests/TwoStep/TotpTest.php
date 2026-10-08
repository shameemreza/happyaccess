<?php
/**
 * Authenticator app code tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Features\TwoStep\Totp;

class TotpTest extends WP_UnitTestCase {

	/**
	 * The 20-byte ASCII secret 12345678901234567890 of RFC 6238, in base32.
	 */
	const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

	/**
	 * RFC 6238 Appendix B, SHA1 column. The RFC lists 8 digits; the 6-digit
	 * code is the same HOTP value modulo 10^6.
	 *
	 * @return array
	 */
	public function provide_rfc_vectors() {
		return array(
			'59'          => array( 59, '287082' ),
			'1111111109'  => array( 1111111109, '081804' ),
			'1111111111'  => array( 1111111111, '050471' ),
			'1234567890'  => array( 1234567890, '005924' ),
			'2000000000'  => array( 2000000000, '279037' ),
			'20000000000' => array( 20000000000, '353130' ),
		);
	}

	/**
	 * @dataProvider provide_rfc_vectors
	 */
	public function test_code_matches_the_rfc_6238_sha1_vectors( $time, $expected ) {
		$this->assertSame( $expected, Totp::code( self::RFC_SECRET, intdiv( $time, 30 ) ) );
	}

	public function test_new_secret_is_32_unpadded_base32_characters_and_differs_each_time() {
		$one = Totp::new_secret();
		$two = Totp::new_secret();
		$this->assertMatchesRegularExpression( '/^[A-Z2-7]{32}$/', $one );
		$this->assertNotSame( $one, $two );
	}

	public function test_a_new_secret_gives_a_six_digit_code() {
		$this->assertMatchesRegularExpression( '/^\d{6}$/', Totp::code( Totp::new_secret(), 1234 ) );
	}

	public function test_match_accepts_the_step_before_the_current_one_and_after() {
		$now  = 1111111111;
		$step = intdiv( $now, 30 );
		foreach ( array( $step - 1, $step, $step + 1 ) as $offered ) {
			$this->assertSame( $offered, Totp::match( self::RFC_SECRET, Totp::code( self::RFC_SECRET, $offered ), $now, 0 ) );
		}
	}

	public function test_match_refuses_a_step_two_away() {
		$now  = 1111111111;
		$step = intdiv( $now, 30 );
		$this->assertFalse( Totp::match( self::RFC_SECRET, Totp::code( self::RFC_SECRET, $step - 2 ), $now, 0 ) );
		$this->assertFalse( Totp::match( self::RFC_SECRET, Totp::code( self::RFC_SECRET, $step + 2 ), $now, 0 ) );
	}

	public function test_match_refuses_a_replay_of_the_same_step() {
		$now  = 1111111111;
		$step = intdiv( $now, 30 );
		$code = Totp::code( self::RFC_SECRET, $step );
		$this->assertSame( $step, Totp::match( self::RFC_SECRET, $code, $now, $step - 1 ) );
		$this->assertFalse( Totp::match( self::RFC_SECRET, $code, $now, $step ) );
	}

	public function test_match_refuses_an_older_step_than_the_last_used_one() {
		$now  = 1111111111;
		$step = intdiv( $now, 30 );
		$old  = Totp::code( self::RFC_SECRET, $step - 1 );
		$this->assertFalse( Totp::match( self::RFC_SECRET, $old, $now, $step ) );
		$this->assertFalse( Totp::match( self::RFC_SECRET, $old, $now, $step + 5 ) );
	}

	public function test_match_ignores_spaces_and_refuses_a_wrong_or_malformed_code() {
		$now  = 1111111111;
		$step = intdiv( $now, 30 );
		$code = Totp::code( self::RFC_SECRET, $step );
		$this->assertSame( $step, Totp::match( self::RFC_SECRET, substr( $code, 0, 3 ) . ' ' . substr( $code, 3 ), $now, 0 ) );

		$wrong = sprintf( '%06d', ( (int) $code + 1 ) % 1000000 );
		$this->assertFalse( Totp::match( self::RFC_SECRET, $wrong, $now, 0 ) );
		$this->assertFalse( Totp::match( self::RFC_SECRET, '', $now, 0 ) );
		$this->assertFalse( Totp::match( self::RFC_SECRET, '12345', $now, 0 ) );
		$this->assertFalse( Totp::match( self::RFC_SECRET, $code . '0', $now, 0 ) );
		$this->assertFalse( Totp::match( 'not base32 !!', $code, $now, 0 ) );
	}

	public function test_uri_has_the_issuer_algorithm_digits_and_period() {
		$uri = Totp::uri( self::RFC_SECRET, 'sam@example.com', 'Example Shop' );
		$this->assertSame(
			'otpauth://totp/Example%20Shop%3Asam%40example.com?secret=' . self::RFC_SECRET . '&issuer=Example%20Shop&algorithm=SHA1&digits=6&period=30',
			$uri
		);
	}

	public function test_uri_encodes_a_plus_sign_and_other_reserved_characters() {
		$uri = Totp::uri( self::RFC_SECRET, 'sam+shop@example.com', 'A&B Store' );
		$this->assertStringContainsString( 'sam%2Bshop%40example.com', $uri );
		$this->assertStringNotContainsString( 'sam+shop', $uri );
		$this->assertStringContainsString( 'issuer=A%26B%20Store', $uri );
		$parts = wp_parse_url( $uri );
		$this->assertSame( 'otpauth', $parts['scheme'] );
		$this->assertSame( 'totp', $parts['host'] );
	}
}
