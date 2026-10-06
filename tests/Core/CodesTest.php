<?php
/**
 * Codes tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Codes;

class CodesTest extends WP_UnitTestCase {

	public function test_numeric_codes_have_requested_length_and_vary() {
		$seen = array();
		for ( $i = 0; $i < 50; $i++ ) {
			$code = Codes::numeric( 8 );
			$this->assertMatchesRegularExpression( '/^\d{8}$/', $code );
			$seen[ $code ] = true;
		}
		$this->assertGreaterThan( 45, count( $seen ) );
		$this->assertMatchesRegularExpression( '/^\d{6}$/', Codes::numeric( 6 ) );
	}

	public function test_link_key_shape() {
		$key = Codes::link_key();
		$this->assertSame( 43, strlen( $key ) );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/', $key );
		$this->assertNotSame( $key, Codes::link_key() );
	}

	public function test_backup_code_shape() {
		$this->assertMatchesRegularExpression( '/^[A-HJ-NP-Z2-9]{10}$/', Codes::backup_code() );
	}

	public function test_hash_ignores_spaces_and_verify_works() {
		$hash = Codes::hash_code( '48291375' );
		$this->assertSame( $hash, Codes::hash_code( '4829 1375' ) );
		$this->assertTrue( Codes::verify_code( '4829-1375', $hash ) );
		$this->assertFalse( Codes::verify_code( '48291376', $hash ) );
		$this->assertFalse( Codes::verify_code( '', $hash ) );
		$this->assertFalse( Codes::verify_code( '48291375', '' ) );
		$this->assertFalse( Codes::verify_code( array(), $hash ) );
	}

	public function test_code_and_key_hashes_use_different_domains() {
		$this->assertNotSame( Codes::hash_code( '123456' ), Codes::hash_key( '123456' ) );
		$key = Codes::link_key();
		$this->assertTrue( Codes::verify_key( $key, Codes::hash_key( $key ) ) );
		$this->assertFalse( Codes::verify_key( $key . 'x', Codes::hash_key( $key ) ) );
	}

	public function test_format_code() {
		$this->assertSame( '4829 1375', Codes::format_code( '48291375' ) );
		$this->assertSame( '482 913', Codes::format_code( '482913' ) );
	}
}
