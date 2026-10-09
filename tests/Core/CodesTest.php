<?php
/**
 * Codes tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Codes;
use HappyAccess\Core\Secrets;

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

	public function tear_down() {
		delete_option( Codes::PURPOSE_SINCE_OPTION );
		parent::tear_down();
	}

	public function test_hash_ignores_spaces_and_verify_works() {
		$hash = Codes::hash_code( '48291375', Codes::PURPOSE_SUPPORT );
		$this->assertSame( $hash, Codes::hash_code( '4829 1375', Codes::PURPOSE_SUPPORT ) );
		$this->assertTrue( Codes::verify_code( '4829-1375', $hash, Codes::PURPOSE_SUPPORT ) );
		$this->assertFalse( Codes::verify_code( '48291376', $hash, Codes::PURPOSE_SUPPORT ) );
		$this->assertFalse( Codes::verify_code( '', $hash, Codes::PURPOSE_SUPPORT ) );
		$this->assertFalse( Codes::verify_code( '48291375', '', Codes::PURPOSE_SUPPORT ) );
		$this->assertFalse( Codes::verify_code( array(), $hash, Codes::PURPOSE_SUPPORT ) );
	}

	public function test_each_purpose_hashes_the_same_digits_differently() {
		$hashes = array(
			Codes::hash_code( '123456', Codes::PURPOSE_SUPPORT ),
			Codes::hash_code( '123456', Codes::PURPOSE_PASSWORDLESS ),
			Codes::hash_code( '123456', Codes::PURPOSE_TWOSTEP_EMAIL ),
			Codes::legacy_hash_code( '123456' ),
		);
		$this->assertCount( 4, array_unique( $hashes ) );

		$support = Codes::hash_code( '123456', Codes::PURPOSE_SUPPORT );
		$this->assertFalse( Codes::verify_code( '123456', $support, Codes::PURPOSE_PASSWORDLESS ), 'A code from one place never passes in another.' );
		$this->assertTrue( Codes::verify_code( '123456', $support, Codes::PURPOSE_SUPPORT, true ), 'Allowing the old hash still accepts the new one.' );
	}

	public function test_a_hash_without_a_purpose_passes_only_when_the_row_allows_it() {
		$legacy = Codes::legacy_hash_code( '4829 1375' );
		$this->assertSame( Secrets::hmac( 'code:48291375' ), $legacy, 'The hash codes issued before purposes were added.' );
		$this->assertTrue( Codes::verify_code( '48291375', $legacy, Codes::PURPOSE_SUPPORT, true ) );
		$this->assertFalse( Codes::verify_code( '48291375', $legacy, Codes::PURPOSE_SUPPORT ) );
		$this->assertFalse( Codes::verify_code( '48291375', $legacy, Codes::PURPOSE_SUPPORT, false ) );
		$this->assertFalse( Codes::verify_code( '48291376', $legacy, Codes::PURPOSE_SUPPORT, true ) );
	}

	public function test_rows_made_before_purposes_accept_the_old_hash() {
		delete_option( Codes::PURPOSE_SINCE_OPTION );
		$this->assertTrue( Codes::accepts_legacy( '2026-01-01 00:00:00' ), 'Without the date nothing is locked out.' );

		update_option( Codes::PURPOSE_SINCE_OPTION, '2026-10-01 12:00:00', false );
		$this->assertTrue( Codes::accepts_legacy( '2026-10-01 11:59:59' ) );
		$this->assertTrue( Codes::accepts_legacy( '2026-10-01 12:00:00' ) );
		$this->assertFalse( Codes::accepts_legacy( '2026-10-01 12:00:01' ) );
		$this->assertTrue( Codes::accepts_legacy( '' ), 'A row with no date predates the change.' );
		$this->assertTrue( Codes::accepts_legacy( null ) );
	}

	public function test_code_and_key_hashes_use_different_domains() {
		$this->assertNotSame( Codes::hash_code( '123456', Codes::PURPOSE_SUPPORT ), Codes::hash_key( '123456' ) );
		$this->assertNotSame( Codes::legacy_hash_code( '123456' ), Codes::hash_key( '123456' ) );
		$key = Codes::link_key();
		$this->assertTrue( Codes::verify_key( $key, Codes::hash_key( $key ) ) );
		$this->assertFalse( Codes::verify_key( $key . 'x', Codes::hash_key( $key ) ) );
	}

	public function test_format_code() {
		$this->assertSame( '4829 1375', Codes::format_code( '48291375' ) );
		$this->assertSame( '482 913', Codes::format_code( '482913' ) );
	}
}
