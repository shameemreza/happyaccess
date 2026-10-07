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
		array( 'self', 'buffer_role_event' ),
	);

	/**
	 * Methods that take the event key as a variable. Their callers are scanned.
	 */
	const WRAPPERS = array(
		'Grants::log',
		'ActivityTracker::record',
		'ActivityTracker::record_post',
		'ActivityTracker::flush',
	);

	/**
	 * Event keys found in src/, and the places where a key could not be read.
	 *
	 * @return array{0: array<string, string[]>, 1: string[]} Keys with the files that write them, then problems.
	 */
	private function scan() {
		$sources = array();
		$root    = dirname( __DIR__, 2 ) . '/src';
		$files   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$sources[ substr( $file->getPathname(), strlen( $root ) - 3 ) ] = (string) file_get_contents( $file->getPathname() );
			}
		}
		return $this->scan_sources( $sources );
	}

	/**
	 * Event keys found in some PHP sources, and the places where a key could not be read.
	 *
	 * @param array<string, string> $sources PHP code by path.
	 * @return array{0: array<string, string[]>, 1: string[]} Keys with the files that write them, then problems.
	 */
	private function scan_sources( array $sources ) {
		$keys     = array();
		$problems = array();

		foreach ( $sources as $path => $code ) {
			$tokens = array_values(
				array_filter(
					token_get_all( $code ),
					static function ( $token ) {
						return ! is_array( $token ) || ! in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
					}
				)
			);

			$class   = '';
			$wrapper = '';
			$count   = count( $tokens );
			for ( $i = 0; $i < $count; $i++ ) {
				// Track the class and the method being read.
				if ( is_array( $tokens[ $i ] ) && T_CLASS === $tokens[ $i ][0] && isset( $tokens[ $i + 1 ][1] ) ) {
					$class = $tokens[ $i + 1 ][1];
					continue;
				}
				if ( is_array( $tokens[ $i ] ) && T_FUNCTION === $tokens[ $i ][0] && isset( $tokens[ $i + 1 ][1] ) ) {
					$wrapper = $class . '::' . $tokens[ $i + 1 ][1];
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
				if ( $ok && is_array( $arg[0] ) && '$event' === $arg[0][1] && 1 === count( $arg ) && in_array( $wrapper, self::WRAPPERS, true ) ) {
					// A listed wrapper that passes its own key on. Its callers are scanned.
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
	 * static:: and $this-> count as self::, and a qualified class name counts
	 * by its last part, so \HappyAccess\Core\AuditLog::add() is AuditLog::add().
	 *
	 * @param array $tokens Tokens without whitespace or comments.
	 * @param int   $i      Index.
	 * @return string The call as text, or an empty string.
	 */
	private function call_at( array $tokens, $i ) {
		if ( ! isset( $tokens[ $i + 3 ] ) || ! is_array( $tokens[ $i ] ) || ! is_array( $tokens[ $i + 1 ] ) || ! is_array( $tokens[ $i + 2 ] ) ) {
			return '';
		}
		if ( '(' !== $tokens[ $i + 3 ] ) {
			return '';
		}
		$class = $tokens[ $i ][1];
		if ( T_DOUBLE_COLON === $tokens[ $i + 1 ][0] ) {
			if ( 'static' === strtolower( $class ) ) {
				$class = 'self';
			}
			$class = substr( (string) strrchr( '\\' . $class, '\\' ), 1 );
		} elseif ( T_OBJECT_OPERATOR === $tokens[ $i + 1 ][0] && '$this' === $class ) {
			$class = 'self';
		} else {
			return '';
		}
		foreach ( self::CALLS as $call ) {
			if ( $call[0] === $class && $call[1] === $tokens[ $i + 2 ][1] ) {
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

	public function test_the_scan_reads_static_this_and_qualified_calls() {
		$code = '<?php
		final class Sample {
			public function a() {
				static::log( \'grant_created\', array() );
				$this->record( \'post_created\', \'x\' );
				\HappyAccess\Core\AuditLog::add( \'plugin_upgraded\' );
				HappyAccess\Core\AuditLog::add( \'login_success\' );
				$this->record( $event, \'x\' );
				static::record_post( $other, \'Updated\', $post );
				$that->record( \'not_watched\' );
			}
		}';

		list( $keys, $problems ) = $this->scan_sources( array( 'Sample.php' => $code ) );

		$this->assertSame( array( 'grant_created', 'post_created', 'plugin_upgraded', 'login_success' ), array_keys( $keys ) );
		$this->assertCount( 2, $problems, implode( "\n", $problems ) );
	}

	public function test_every_agent_and_core_event_is_still_written_somewhere() {
		list( $keys, ) = $this->scan();
		$unused        = array_values( array_diff( array_merge( Privacy::AGENT_EVENTS, Privacy::CORE_EVENTS ), array_keys( $keys ) ) );
		$this->assertSame( array(), $unused, "These events are listed but nothing in src/ writes them:\n" . implode( "\n", $unused ) );
	}
}
