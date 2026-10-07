<?php
/**
 * Autoloader tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Autoloader;

class AutoloaderTest extends WP_UnitTestCase {

	public function test_a_plugin_class_maps_to_its_file() {
		$file = Autoloader::file_for( 'HappyAccess\Core\Clock' );
		$this->assertSame( realpath( dirname( __DIR__ ) . '/src/Core/Clock.php' ), realpath( $file ) );
	}

	public function test_other_namespaces_are_not_ours() {
		$this->assertSame( '', Autoloader::file_for( 'Other\Core\Clock' ) );
		$this->assertSame( '', Autoloader::file_for( 'HappyAccessory\Clock' ) );
	}

	public function test_a_class_that_has_no_file_maps_to_nothing() {
		$this->assertSame( '', Autoloader::file_for( 'HappyAccess\Core\NoSuchThing' ) );
	}

	public function provide_bad_names() {
		return array(
			'parent folder'  => array( 'HappyAccess\Core\..\Plugin' ),
			'forward slash'  => array( 'HappyAccess/Core/Clock' ),
			'dot'            => array( 'HappyAccess\Core\Clock.php' ),
			'null byte'      => array( "HappyAccess\\Core\\Clock\0" ),
			'space'          => array( 'HappyAccess\Core\Cl ock' ),
			'dash'           => array( 'HappyAccess\Core\Clock-x' ),
			'colon'          => array( 'HappyAccess\Core:Clock' ),
			'stream wrapper' => array( 'HappyAccess\php://filter' ),
		);
	}

	/**
	 * @dataProvider provide_bad_names
	 */
	public function test_names_outside_the_class_alphabet_map_to_nothing( $name ) {
		$this->assertSame( '', Autoloader::file_for( $name ) );
	}

	public function test_load_ignores_a_bad_name_without_error() {
		Autoloader::load( 'HappyAccess\Core\..\Plugin' );
		$this->assertTrue( true );
	}
}
