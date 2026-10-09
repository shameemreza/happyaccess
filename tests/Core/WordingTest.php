<?php
/**
 * The feature is called Temporary access wherever a person reads it.
 *
 * @package HappyAccess
 */

class WordingTest extends WP_UnitTestCase {

	/**
	 * Code-only places that may still hold the old name, as a path under
	 * the plugin folder mapped to the reason. Empty for now.
	 *
	 * @var array<string,string>
	 */
	const ALLOWED = array();

	/**
	 * Every PHP file under a folder of the plugin.
	 *
	 * @param string $dir Folder under the plugin folder.
	 * @return string[] Paths relative to the plugin folder.
	 */
	private function php_files( $dir ) {
		$files    = array();
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( HAPPYACCESS_PLUGIN_DIR . $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$files[] = substr( $file->getPathname(), strlen( HAPPYACCESS_PLUGIN_DIR ) );
			}
		}
		sort( $files );
		return $files;
	}

	/**
	 * The text a person can read in a PHP file: its strings and its HTML.
	 * Comments and docblocks are left out.
	 *
	 * @param string $file Path under the plugin folder.
	 * @return string[]
	 */
	private function readable_text( $file ) {
		$kinds = array( T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML );
		$text  = array();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local plugin file.
		foreach ( token_get_all( (string) file_get_contents( HAPPYACCESS_PLUGIN_DIR . $file ) ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], $kinds, true ) ) {
				$text[] = $token[1];
			}
		}
		return $text;
	}

	public function test_comments_do_not_count_but_strings_do() {
		$tokens = token_get_all( "<?php\n// Support access\n\$a = 'Support access';" );
		$found  = array();
		foreach ( $tokens as $token ) {
			if ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
				$found[] = $token[1];
			}
		}
		$this->assertSame( array( "'Support access'" ), $found );
	}

	public function test_no_screen_email_or_notice_says_support_access() {
		$found = array();
		foreach ( array_merge( $this->php_files( 'src' ), $this->php_files( 'templates' ) ) as $file ) {
			if ( isset( self::ALLOWED[ $file ] ) ) {
				continue;
			}
			foreach ( $this->readable_text( $file ) as $text ) {
				if ( false !== stripos( $text, 'support access' ) ) {
					$found[] = $file . ': ' . trim( $text );
				}
			}
		}
		$this->assertSame( array(), $found );
	}
}
