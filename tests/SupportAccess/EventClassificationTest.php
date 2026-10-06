<?php
/**
 * Every logged event key must be classified for the privacy exporter and eraser.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Privacy;

class EventClassificationTest extends WP_UnitTestCase {

	/**
	 * Calls whose first argument is an event key, as class and method.
	 */
	const CALLS = array(
		array( 'AuditLog', 'add' ),
		array( 'self', 'log' ),
		array( 'self', 'record' ),
		array( 'self', 'record_post' ),
	);

	/**
	 * Event keys found in src/, and the places where a key could not be read.
	 *
	 * @return array{0: array<string, string[]>, 1: string[]} Keys with the files that write them, then problems.
	 */
	private function scan() {
		$keys     = array();
		$problems = array();
		$root     = dirname( __DIR__, 2 ) . '/src';
		$files    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );

		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$path   = substr( $file->getPathname(), strlen( $root ) - 3 );
			$tokens = array_values(
				array_filter(
					token_get_all( (string) file_get_contents( $file->getPathname() ) ),
					static function ( $token ) {
						return ! is_array( $token ) || ! in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
					}
				)
			);

			$wrapper = '';
			$count   = count( $tokens );
			for ( $i = 0; $i < $count; $i++ ) {
				// Track the first parameter name of the method being read.
				if ( is_array( $tokens[ $i ] ) && T_FUNCTION === $tokens[ $i ][0] ) {
					$wrapper = '';
					for ( $j = $i + 1; $j < $count && '(' !== $tokens[ $j ]; $j++ ) {
						continue;
					}
					if ( isset( $tokens[ $j + 1 ] ) && is_array( $tokens[ $j + 1 ] ) && T_VARIABLE === $tokens[ $j + 1 ][0] ) {
						$wrapper = $tokens[ $j + 1 ][1];
					}
					continue;
				}

				$hit = $this->call_at( $tokens, $i );
				if ( ! $hit ) {
					continue;
				}

				$line = $tokens[ $i ][2];
				$arg  = $this->first_argument( $tokens, $i + 4 );

				$literals = array();
				$ok       = ! empty( $arg );
				if ( $ok && '$event' === $arg[0][1] && 1 === count( $arg ) && '$event' === $wrapper ) {
					// A wrapper that takes the key as its own first argument. Its callers are scanned.
					continue;
				}
				$question = array_search( '?', $arg, true );
				if ( false !== $question ) {
					$arg = array_slice( $arg, $question + 1 );
				}
				foreach ( $arg as $token ) {
					if ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
						$literals[] = trim( $token[1], '\'"' );
					} elseif ( ':' !== $token || false === $question ) {
						$ok = false;
					}
				}
				if ( ! $ok || ! $literals ) {
					$problems[] = $path . ':' . $line . ' passes ' . $hit . ' a first argument that is not a string literal';
					continue;
				}
				foreach ( $literals as $literal ) {
					$keys[ $literal ][] = $path . ':' . $line;
				}
			}
		}

		return array( $keys, $problems );
	}

	/**
	 * Whether the tokens at an index open one of the watched calls: Class, ::, method, (.
	 *
	 * @param array $tokens Tokens without whitespace or comments.
	 * @param int   $i      Index.
	 * @return string The call as text, or an empty string.
	 */
	private function call_at( array $tokens, $i ) {
		if ( ! isset( $tokens[ $i + 3 ] ) || ! is_array( $tokens[ $i ] ) || ! is_array( $tokens[ $i + 2 ] ) ) {
			return '';
		}
		if ( T_DOUBLE_COLON !== $tokens[ $i + 1 ][0] || '(' !== $tokens[ $i + 3 ] ) {
			return '';
		}
		foreach ( self::CALLS as $call ) {
			if ( $call[0] === $tokens[ $i ][1] && $call[1] === $tokens[ $i + 2 ][1] ) {
				return $call[0] . '::' . $call[1] . '()';
			}
		}
		return '';
	}

	/**
	 * Tokens of the first argument, up to the first top level comma or closing bracket.
	 *
	 * @param array $tokens Tokens without whitespace or comments.
	 * @param int   $from   Index of the first token after the opening bracket.
	 * @return array
	 */
	private function first_argument( array $tokens, $from ) {
		$arg   = array();
		$depth = 0;
		$count = count( $tokens );
		for ( $i = $from; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( in_array( $token, array( '(', '[' ), true ) ) {
				++$depth;
			} elseif ( in_array( $token, array( ')', ']' ), true ) ) {
				if ( 0 === $depth ) {
					break;
				}
				--$depth;
			} elseif ( ',' === $token && 0 === $depth ) {
				break;
			}
			$arg[] = $token;
		}
		return $arg;
	}

	public function test_the_scan_finds_events() {
		list( $keys, ) = $this->scan();
		$this->assertArrayHasKey( 'grant_created', $keys );
		$this->assertArrayHasKey( 'login_success', $keys );
		$this->assertArrayHasKey( 'plugin_upgraded', $keys );
		$this->assertArrayHasKey( 'post_created', $keys );
		$this->assertArrayHasKey( 'post_updated', $keys );
		$this->assertArrayHasKey( 'grant_suspended', $keys );
	}

	public function test_every_event_key_is_a_string_literal() {
		list( , $problems ) = $this->scan();
		$this->assertSame( array(), $problems, "Event keys must be string literals so they can be classified:\n" . implode( "\n", $problems ) );
	}

	public function test_every_logged_event_is_classified() {
		list( $keys, ) = $this->scan();
		$known   = array_merge( Privacy::ADMIN_EVENTS, Privacy::AGENT_EVENTS, Privacy::CORE_EVENTS );
		$missing = array();
		foreach ( $keys as $key => $places ) {
			if ( ! in_array( $key, $known, true ) ) {
				$missing[] = $key . ' (' . implode( ', ', $places ) . ')';
			}
		}
		$this->assertSame( array(), $missing, "Classify these events in Privacy::ADMIN_EVENTS, AGENT_EVENTS or CORE_EVENTS:\n" . implode( "\n", $missing ) );
	}

	public function test_no_event_is_in_two_lists() {
		$all = array_merge( Privacy::ADMIN_EVENTS, Privacy::AGENT_EVENTS, Privacy::CORE_EVENTS );
		$this->assertSame( array(), array_values( array_diff_key( $all, array_unique( $all ) ) ), 'An event key appears in more than one list.' );
	}
}
