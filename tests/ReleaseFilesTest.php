<?php
/**
 * Release file tests: the version in every place, and what the .distignore
 * lets into the zip.
 *
 * @package HappyAccess
 */

class ReleaseFilesTest extends WP_UnitTestCase {

	/**
	 * Plugin root, with a trailing slash.
	 *
	 * @return string
	 */
	private function root() {
		return trailingslashit( dirname( __DIR__ ) );
	}

	/**
	 * The HAPPYACCESS_VERSION the main file defines. The test bootstrap
	 * defines the constant first, so it is read from the source.
	 *
	 * @return string
	 */
	private function constant_in_main_file() {
		$source = (string) file_get_contents( $this->root() . 'happyaccess.php' );
		$this->assertSame( 1, preg_match( "/define\(\s*'HAPPYACCESS_VERSION',\s*'([^']+)'\s*\)/", $source, $match ) );
		return $match[1];
	}

	/**
	 * Readme header fields by name.
	 *
	 * @return array
	 */
	private function readme_headers() {
		return get_file_data(
			$this->root() . 'readme.txt',
			array(
				'stable' => 'Stable tag',
				'tested' => 'Tested up to',
			)
		);
	}

	public function test_the_version_is_the_same_in_the_header_the_constant_package_json_and_the_readme() {
		$header  = get_file_data( $this->root() . 'happyaccess.php', array( 'version' => 'Version' ) );
		$package = json_decode( (string) file_get_contents( $this->root() . 'package.json' ), true );
		$readme  = $this->readme_headers();

		$this->assertSame( '1.1.0', $header['version'] );
		$this->assertSame( $header['version'], $this->constant_in_main_file() );
		$this->assertSame( $header['version'], $package['version'] );
		$this->assertSame( $header['version'], $readme['stable'] );
		$this->assertSame( $header['version'], HAPPYACCESS_VERSION, 'tests/load-plugin.php defines another version' );
	}

	public function test_the_header_describes_the_three_features_in_plain_words() {
		$header = get_file_data( $this->root() . 'happyaccess.php', array( 'description' => 'Description' ) );
		$this->assertSame( 'Give support temporary access without sharing a password, let people log in with an email code, and add two-step login.', $header['description'] );
	}

	public function test_tested_up_to_is_7_1_in_the_header_and_the_readme() {
		$header = get_file_data( $this->root() . 'happyaccess.php', array( 'tested' => 'Tested up to' ) );

		$this->assertSame( '7.1', $header['tested'] );
		$this->assertSame( '7.1', $this->readme_headers()['tested'] );
	}

	/**
	 * The .distignore patterns, without blank lines and comments.
	 *
	 * @return string[]
	 */
	private function distignore_patterns() {
		$lines = file( $this->root() . '.distignore', FILE_IGNORE_NEW_LINES );
		return array_values(
			array_filter(
				array_map( 'trim', (array) $lines ),
				static function ( $line ) {
					return '' !== $line && '#' !== $line[0];
				}
			)
		);
	}

	/**
	 * Whether a path matches a pattern, the way a .gitignore line does: a
	 * leading slash or a slash inside anchors the pattern at the root, a
	 * trailing slash matches folders only, and anything else matches the
	 * name at any depth.
	 *
	 * @param string $path    Path relative to the root, no leading slash.
	 * @param bool   $is_dir  Whether the path is a folder.
	 * @param string $pattern One .distignore line.
	 * @return bool
	 */
	private function pattern_matches( $path, $is_dir, $pattern ) {
		if ( '/' === substr( $pattern, -1 ) ) {
			if ( ! $is_dir ) {
				return false;
			}
			$pattern = rtrim( $pattern, '/' );
		}
		if ( false !== strpos( $pattern, '/' ) ) {
			return fnmatch( ltrim( $pattern, '/' ), $path, FNM_PATHNAME );
		}
		return fnmatch( $pattern, basename( $path ) );
	}

	/**
	 * The files a dist archive would hold: every file under the root that no
	 * pattern matches, and no folder above it matches either.
	 *
	 * @param string[] $patterns .distignore lines.
	 * @param string   $sub      Folder to walk, relative to the root.
	 * @return string[]
	 */
	private function dist_files( array $patterns, $sub = '' ) {
		$files = array();
		foreach ( (array) scandir( $this->root() . $sub ) as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$path   = ltrim( $sub . '/' . $name, '/' );
			$is_dir = is_dir( $this->root() . $path );
			foreach ( $patterns as $pattern ) {
				if ( $this->pattern_matches( $path, $is_dir, $pattern ) ) {
					continue 2;
				}
			}
			if ( $is_dir ) {
				$files = array_merge( $files, $this->dist_files( $patterns, $path ) );
			} else {
				$files[] = $path;
			}
		}
		return $files;
	}

	public function test_the_distignore_names_the_folders_and_files_kept_out_of_the_release() {
		$patterns = $this->distignore_patterns();
		foreach ( array( '/.superpowers', '/_trash', '/.claude', '/review-repro', '/patches', '/admin-app', '/tests', '/vitest*', '/phpcs.xml.dist', '/node_modules', '/vendor' ) as $required ) {
			$this->assertContains( $required, $patterns );
		}
	}

	public function test_the_dist_file_list_has_no_tests_app_source_or_dotfiles() {
		$files = $this->dist_files( $this->distignore_patterns() );

		$this->assertNotEmpty( $files );
		foreach ( $files as $file ) {
			foreach ( array( 'tests/', 'admin-app/', 'vendor/', 'node_modules/', '_trash/', 'review-repro/', 'patches/' ) as $folder ) {
				$this->assertStringStartsNotWith( $folder, $file );
			}
			foreach ( explode( '/', $file ) as $part ) {
				$this->assertStringStartsNotWith( '.', $part, $file . ' is a dotfile' );
			}
			$this->assertStringStartsNotWith( 'vitest', $file );
			$this->assertNotContains( $file, array( 'composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'phpcs.xml.dist', 'phpunit.xml.dist' ) );
		}
	}

	public function test_the_dist_file_list_keeps_the_plugin_files() {
		$files = $this->dist_files( $this->distignore_patterns() );

		$kept = array(
			'happyaccess.php',
			'uninstall.php',
			'readme.txt',
			'src/Plugin.php',
			'templates/login/passwordless-form.php',
			'blocks/login/block.json',
			'blocks/login/render.php',
			'assets/login.js',
			'assets/vendor/qrcode.js',
			'assets/vendor/qrcode-LICENSE.txt',
			'languages/happyaccess.pot',
		);
		if ( is_readable( $this->root() . 'build/index.js' ) ) {
			$kept = array_merge( $kept, array( 'build/index.js', 'build/index.asset.php', 'build/style-index.css', 'build/blocks.js', 'build/blocks.asset.php' ) );
		}
		foreach ( $kept as $file ) {
			$this->assertContains( $file, $files );
		}
	}
}
