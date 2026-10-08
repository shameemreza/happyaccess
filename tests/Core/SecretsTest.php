<?php
/**
 * Secrets tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Secrets;

class SecretsTest extends WP_UnitTestCase {

	public function test_key_is_created_once_with_autoload_off() {
		global $wpdb;
		delete_option( Secrets::OPTION );
		Secrets::reset_cache();

		$first  = Secrets::key();
		$second = Secrets::key();

		$this->assertSame( 32, strlen( $first ) );
		$this->assertSame( $first, $second );

		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Secrets::OPTION ) );
		$this->assertContains( $autoload, array( 'no', 'off' ) );
	}

	public function test_hmac_is_stable_hex() {
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', Secrets::hmac( 'abc' ) );
		$this->assertSame( Secrets::hmac( 'abc' ), Secrets::hmac( 'abc' ) );
		$this->assertNotSame( Secrets::hmac( 'abc' ), Secrets::hmac( 'abd' ) );
	}

	public function test_encrypt_round_trip_both_engines() {
		foreach ( array( 's1', 'o1' ) as $engine ) {
			$payload = Secrets::encrypt_with( $engine, 'JBSWY3DPEHPK3PXP' );
			$this->assertStringStartsWith( $engine . ':', $payload );
			$this->assertSame( 'JBSWY3DPEHPK3PXP', Secrets::decrypt( $payload ) );
		}
		$this->assertSame( 'x', Secrets::decrypt( Secrets::encrypt( 'x' ) ) );
	}

	public function test_tampered_or_garbage_payload_returns_null() {
		$payload  = Secrets::encrypt_with( 's1', 'secret' );
		$tampered = substr_replace( $payload, 'A' === $payload[10] ? 'B' : 'A', 10, 1 );
		$this->assertNull( Secrets::decrypt( $tampered ) );
		$this->assertNull( Secrets::decrypt( 'zz:abc' ) );
		$this->assertNull( Secrets::decrypt( '' ) );
	}

	public function test_o1_tampered_payload_returns_null() {
		$payload  = Secrets::encrypt_with( 'o1', 'secret' );
		$tampered = substr_replace( $payload, 'A' === $payload[10] ? 'B' : 'A', 10, 1 );
		$this->assertNull( Secrets::decrypt( $tampered ) );
	}

	public function test_unknown_engine_throws() {
		$this->expectException( \InvalidArgumentException::class );
		Secrets::encrypt_with( 'x9', 'secret' );
	}

	public function test_corrupt_stored_key_is_replaced_with_autoload_off() {
		global $wpdb;
		update_option( Secrets::OPTION, 'not-base64!!' );
		Secrets::reset_cache();

		$key = Secrets::key();

		$this->assertSame( 32, strlen( $key ) );
		$this->assertSame( base64_encode( $key ), get_option( Secrets::OPTION ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Comparing the stored key.
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Secrets::OPTION ) );
		$this->assertContains( $autoload, array( 'no', 'off' ) );
	}

	public function test_existing_valid_key_is_not_rotated() {
		delete_option( Secrets::OPTION );
		Secrets::reset_cache();
		$first  = Secrets::key();
		$stored = get_option( Secrets::OPTION );

		Secrets::reset_cache();

		$this->assertSame( $first, Secrets::key() );
		$this->assertSame( $stored, get_option( Secrets::OPTION ) );
	}

	public function test_empty_stored_key_is_repaired() {
		update_option( Secrets::OPTION, '' );
		Secrets::reset_cache();

		$key = Secrets::key();

		$this->assertSame( 32, strlen( $key ) );
		$this->assertSame( base64_encode( $key ), get_option( Secrets::OPTION ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Comparing the stored key.
	}

	public function test_array_stored_key_is_repaired() {
		update_option( Secrets::OPTION, array( 'junk' ) );
		Secrets::reset_cache();

		$key = Secrets::key();

		$this->assertSame( 32, strlen( $key ) );
		$this->assertSame( base64_encode( $key ), get_option( Secrets::OPTION ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Comparing the stored key.
	}

	public function test_concurrent_creation_adopts_the_winners_key() {
		global $wpdb;
		delete_option( Secrets::OPTION );
		Secrets::reset_cache();

		// Prime the "option is missing" cache, then insert the row behind WordPress's back.
		get_option( Secrets::OPTION, 'x' );
		$winner = str_repeat( 'W', 32 );
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => Secrets::OPTION,
				'option_value' => base64_encode( $winner ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test fixture.
				'autoload'     => 'no',
			)
		);

		$this->assertSame( $winner, Secrets::key() );
		wp_cache_delete( Secrets::OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		$this->assertSame( base64_encode( $winner ), get_option( Secrets::OPTION ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Comparing the stored key.
	}

	public function test_is_persisted() {
		Secrets::reset_cache();
		Secrets::key();
		$this->assertTrue( Secrets::is_persisted() );
		update_option( Secrets::OPTION, 'garbage' );
		$this->assertFalse( Secrets::is_persisted() );
		Secrets::reset_cache();
	}

	public function test_network_encrypt_round_trip() {
		$payload = Secrets::encrypt_network( 'JBSWY3DPEHPK3PXP' );
		$this->assertSame( 'JBSWY3DPEHPK3PXP', Secrets::decrypt_network( $payload ) );
		$this->assertNull( Secrets::decrypt_network( 'zz:abc' ) );
		$this->assertNull( Secrets::decrypt_network( '' ) );
	}

	public function test_on_a_single_site_the_network_key_is_the_site_key() {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Single site only.' );
		}
		$this->assertSame( 'x', Secrets::decrypt( Secrets::encrypt_network( 'x' ) ) );
		$this->assertSame( 'x', Secrets::decrypt_network( Secrets::encrypt( 'x' ) ) );
	}

	public function test_is_network_persisted() {
		Secrets::reset_cache();
		Secrets::key();
		$this->assertTrue( Secrets::is_network_persisted() );
		update_option( Secrets::OPTION, 'garbage' );
		$this->assertFalse( Secrets::is_network_persisted() );
		Secrets::reset_cache();
	}
}
