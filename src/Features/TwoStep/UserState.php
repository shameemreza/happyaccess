<?php
/**
 * What a user has set up for two-step login.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Features\TwoStep;

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Clock;
use HappyAccess\Core\Secrets;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps the three user meta keys of the feature:
 *
 * - _happyaccess_twostep: the email method switch and the grace counters.
 * - _happyaccess_totp: the encrypted app secret and the last step used.
 *   User meta is shared by every site of a network and two-step login
 *   belongs to the person, so the secret uses the network key.
 * - _happyaccess_backup_codes: the hashes of the unused backup codes.
 *
 * - _happyaccess_twostep_recheck: when the user last proved it was them on
 *   the profile, before changing a method, as a map of the login session
 *   (a hash of its token) to the Unix time.
 *
 * The app counts as set up while a secret is stored, even one that no
 * longer opens (after a salt change in wp-config.php, say), so two-step
 * login fails closed: the code step offers email and backup codes until
 * the user sets the app up again. It can make codes only while the secret
 * opens. Backup codes alone never turn two-step login on.
 *
 * Every write that turns a method on or off drops the Coverage counts.
 * Grace writes don't: the counts are kept for 5 minutes and would
 * otherwise be worked out again after every grace login.
 */
final class UserState {

	const META_STATE   = '_happyaccess_twostep';
	const META_TOTP    = '_happyaccess_totp';
	const META_BACKUP  = '_happyaccess_backup_codes';
	const META_RECHECK = '_happyaccess_twostep_recheck';

	/**
	 * Tries of a compare-and-swap write to the state meta.
	 */
	const STATE_TRIES = 3;

	/**
	 * Seconds a change lock in the state meta holds, in case the request
	 * that took it never let go.
	 */
	const LOCK_TTL = 30;

	/**
	 * The methods that can check a code, from app, email and backup, in that
	 * order. The app is listed only while its secret opens. Backup is listed
	 * while codes remain and two-step login is on, because it only stands in
	 * for the other methods.
	 *
	 * @param int $user_id User id.
	 * @return string[]
	 */
	public static function methods( $user_id ) {
		$methods = array();
		if ( self::app_enabled( $user_id ) ) {
			$methods[] = 'app';
		}
		if ( self::email_enabled( $user_id ) ) {
			$methods[] = 'email';
		}
		if ( self::is_enabled( $user_id ) && BackupCodes::remaining( $user_id ) > 0 ) {
			$methods[] = 'backup';
		}
		return $methods;
	}

	/**
	 * The method offered first: the app when it is on, otherwise email.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	public static function default_method( $user_id ) {
		return self::app_enabled( $user_id ) ? 'app' : 'email';
	}

	/**
	 * Whether the user has two-step login: an app secret stored, readable or
	 * not, or the email method on.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public static function is_enabled( $user_id ) {
		return self::app_configured( $user_id ) || self::email_enabled( $user_id );
	}

	/**
	 * Whether the user has an app secret that opens, so app codes work.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public static function app_enabled( $user_id ) {
		return null !== self::totp_secret( $user_id );
	}

	/**
	 * Whether an app secret is stored, whether or not it opens.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public static function app_configured( $user_id ) {
		$totp = get_user_meta( (int) $user_id, self::META_TOTP, true );
		return is_array( $totp ) && ! empty( $totp['secret'] );
	}

	/**
	 * Whether a stored app secret can't be opened any more: the salts in wp-config.php
	 * or the site key changed since it was saved.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public static function secret_unreadable( $user_id ) {
		return self::app_configured( $user_id ) && ! self::app_enabled( $user_id );
	}

	/**
	 * Logs once per stored secret that it can't be opened. The mark sits
	 * beside the secret, so setting the app up again clears it.
	 *
	 * @param int $user_id User id.
	 * @return bool Whether this call wrote the log row.
	 */
	public static function note_unreadable( $user_id ) {
		$row = self::read_raw( $user_id, self::META_TOTP );
		if ( null === $row ) {
			return false;
		}
		$totp = maybe_unserialize( $row['raw'] );
		if ( ! is_array( $totp ) || ! empty( $totp['unreadable'] ) ) {
			return false;
		}
		$totp['unreadable'] = 1;
		// Only the request that marks the secret logs it.
		if ( ! self::swap_raw( $user_id, $row, $totp ) ) {
			return false;
		}
		AuditLog::add( 'twostep_secret_unreadable', self::log_args( $user_id, __( "Authenticator app secret can't be read, so email and backup codes stand in", 'happyaccess' ), 'app' ) );
		return true;
	}

	/**
	 * Whether the user has the email method on.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public static function email_enabled( $user_id ) {
		$state = self::state( $user_id );
		return ! empty( $state['email'] );
	}

	/**
	 * Turns the app method on with a secret, which is stored encrypted. The
	 * last used step starts at 0.
	 *
	 * Refused when the network key is not saved: a secret sealed with a key
	 * held in memory only could not be opened on the next request.
	 *
	 * @param int    $user_id User id.
	 * @param string $secret  Base32 secret from Totp::new_secret().
	 * @return true|\WP_Error
	 */
	public static function enable_app( $user_id, $secret ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 || ! is_string( $secret ) || '' === $secret ) {
			return new \WP_Error( 'happyaccess_bad_request', __( 'The authenticator app could not be set up. Try again later.', 'happyaccess' ) );
		}
		if ( ! Secrets::is_network_persisted() ) {
			return new \WP_Error( 'happyaccess_no_site_key', __( 'The authenticator app could not be set up. Try again later.', 'happyaccess' ) );
		}

		$was_on = self::app_configured( $user_id );
		update_user_meta(
			$user_id,
			self::META_TOTP,
			wp_slash(
				array(
					'secret'    => Secrets::encrypt_network( $secret ),
					'last_step' => 0,
				)
			)
		);
		Coverage::forget();
		if ( ! $was_on ) {
			AuditLog::add( 'twostep_enabled', self::log_args( $user_id, __( 'Two-step login turned on with an authenticator app', 'happyaccess' ), 'app' ) );
		}
		return true;
	}

	/**
	 * Turns the app method off and forgets the secret.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public static function disable_app( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! metadata_exists( 'user', $user_id, self::META_TOTP ) ) {
			return;
		}
		delete_user_meta( $user_id, self::META_TOTP );
		Coverage::forget();
		AuditLog::add( 'twostep_disabled', self::log_args( $user_id, __( 'Authenticator app turned off for two-step login', 'happyaccess' ), 'app' ) );
	}

	/**
	 * Turns the email method on.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public static function enable_email( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 || self::email_enabled( $user_id ) ) {
			return;
		}
		self::save_state( $user_id, array( 'email' => true ) );
		Coverage::forget();
		AuditLog::add( 'twostep_enabled', self::log_args( $user_id, __( 'Two-step login turned on with email codes', 'happyaccess' ), 'email' ) );
	}

	/**
	 * Turns the email method off.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public static function disable_email( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! self::email_enabled( $user_id ) ) {
			return;
		}
		self::save_state( $user_id, array( 'email' => false ) );
		Coverage::forget();
		AuditLog::add( 'twostep_disabled', self::log_args( $user_id, __( 'Email codes turned off for two-step login', 'happyaccess' ), 'email' ) );
	}

	/**
	 * Clears every two-step meta key of the user, as recovery does. The grace
	 * period starts over with them. The log entry is written only when there
	 * was something to clear, a re-check alone included.
	 *
	 * @param int   $user_id User id.
	 * @param array $meta    Log meta: who reset it and from where. Never a secret.
	 * @return bool Whether there was anything to clear.
	 */
	public static function reset( $user_id, array $meta = array() ) {
		$user_id = (int) $user_id;
		$had     = false;
		foreach ( array( self::META_STATE, self::META_TOTP, self::META_BACKUP, self::META_RECHECK ) as $key ) {
			if ( metadata_exists( 'user', $user_id, $key ) ) {
				$had = true;
				delete_user_meta( $user_id, $key );
			}
		}
		if ( $had ) {
			Coverage::forget();
			$args         = self::log_args( $user_id, __( 'Two-step login reset', 'happyaccess' ), '' );
			$args['meta'] = $meta;
			AuditLog::add( 'twostep_reset', $args );
		}
		return $had;
	}

	/**
	 * Runs a change while holding a lock kept in the state meta, taken with
	 * a compare-and-swap write. A second change for the same user that
	 * arrives meanwhile is refused, so two requests can't each pass a check
	 * that only one of them may pass, like turning off the last method.
	 *
	 * @param int      $user_id User id.
	 * @param callable $change  Runs under the lock; its result is returned.
	 * @return mixed|\WP_Error The result of the change, or a 409 error when another change holds the lock.
	 */
	public static function locked( $user_id, $change ) {
		$user_id = (int) $user_id;
		$lock    = wp_generate_password( 20, false );
		if ( ! self::take_lock( $user_id, $lock ) ) {
			return new \WP_Error( 'happyaccess_busy', __( 'Another change to two-step login is running. Try again.', 'happyaccess' ), array( 'status' => 409 ) );
		}
		try {
			return call_user_func( $change );
		} finally {
			self::change_state(
				$user_id,
				static function ( array $state ) use ( $lock ) {
					if ( isset( $state['lock'] ) && $lock === $state['lock'] ) {
						unset( $state['lock'], $state['lock_at'] );
					}
					return $state;
				}
			);
		}
	}

	/**
	 * Takes the change lock: one compare-and-swap write that fails when the
	 * state changed since it was read or another fresh lock is in it.
	 *
	 * @param int    $user_id User id.
	 * @param string $lock    This request's lock id.
	 * @return bool Whether this request holds the lock.
	 */
	private static function take_lock( $user_id, $lock ) {
		$row = self::read_raw( $user_id, self::META_STATE );
		if ( null === $row ) {
			add_user_meta( $user_id, self::META_STATE, array(), true );
			$row = self::read_raw( $user_id, self::META_STATE );
			if ( null === $row ) {
				return false;
			}
		}
		$state = maybe_unserialize( $row['raw'] );
		$state = is_array( $state ) ? $state : array();
		if ( ! empty( $state['lock'] ) && Clock::now() - ( isset( $state['lock_at'] ) ? (int) $state['lock_at'] : 0 ) < self::LOCK_TTL ) {
			return false;
		}
		$state['lock']    = $lock;
		$state['lock_at'] = Clock::now();
		return self::swap_raw( $user_id, $row, $state );
	}

	/**
	 * Forgets the grace period, so a role that becomes required again later
	 * gets a fresh one.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public static function clear_grace( $user_id ) {
		$state = self::state( $user_id );
		if ( ! isset( $state['grace_started_at'] ) && ! isset( $state['grace_logins_used'] ) ) {
			return;
		}
		self::change_state(
			(int) $user_id,
			static function ( array $state ) {
				unset( $state['grace_started_at'], $state['grace_logins_used'] );
				return $state;
			}
		);
	}

	/**
	 * The stored app secret, or null when the app is off or the stored value
	 * can't be opened.
	 *
	 * @param int $user_id User id.
	 * @return string|null Base32 secret.
	 */
	public static function totp_secret( $user_id ) {
		$totp = get_user_meta( (int) $user_id, self::META_TOTP, true );
		if ( ! is_array( $totp ) || empty( $totp['secret'] ) ) {
			return null;
		}
		$secret = Secrets::decrypt_network( (string) $totp['secret'] );
		return ( null === $secret || '' === $secret ) ? null : $secret;
	}

	/**
	 * The last time step used for this user, 0 for none.
	 *
	 * @param int $user_id User id.
	 * @return int
	 */
	public static function last_step( $user_id ) {
		$totp = get_user_meta( (int) $user_id, self::META_TOTP, true );
		return ( is_array( $totp ) && isset( $totp['last_step'] ) ) ? (int) $totp['last_step'] : 0;
	}

	/**
	 * Stores a time step as the last one used. The write only lands if no
	 * other request changed the stored value since it was read, so one step
	 * is used up once even when two requests send it together.
	 *
	 * @param int $user_id User id.
	 * @param int $step    Matched time step.
	 * @return bool Whether this call used the step.
	 */
	public static function consume_step( $user_id, $step ) {
		$row = self::read_raw( $user_id, self::META_TOTP );
		if ( null === $row ) {
			return false;
		}
		$totp = maybe_unserialize( $row['raw'] );
		if ( ! is_array( $totp ) || (int) $step <= (int) ( isset( $totp['last_step'] ) ? $totp['last_step'] : 0 ) ) {
			return false;
		}
		$totp['last_step'] = (int) $step;
		return self::swap_raw( $user_id, $row, $totp );
	}

	/**
	 * Unix time the grace period started, 0 when it hasn't.
	 *
	 * @param int $user_id User id.
	 * @return int
	 */
	public static function grace_started_at( $user_id ) {
		$state = self::state( $user_id );
		return isset( $state['grace_started_at'] ) ? (int) $state['grace_started_at'] : 0;
	}

	/**
	 * Logins made during the grace period.
	 *
	 * @param int $user_id User id.
	 * @return int
	 */
	public static function grace_logins_used( $user_id ) {
		$state = self::state( $user_id );
		return isset( $state['grace_logins_used'] ) ? (int) $state['grace_logins_used'] : 0;
	}

	/**
	 * Starts the grace period. A period that has started keeps its time.
	 *
	 * @param int      $user_id User id.
	 * @param int|null $now     Unix time, default now.
	 * @return void
	 */
	public static function start_grace( $user_id, $now = null ) {
		if ( self::grace_started_at( $user_id ) > 0 ) {
			return;
		}
		$started = null === $now ? Clock::now() : (int) $now;
		self::change_state(
			(int) $user_id,
			static function ( array $state ) use ( $started ) {
				if ( empty( $state['grace_started_at'] ) ) {
					$state['grace_started_at'] = $started;
				}
				return $state;
			}
		);
	}

	/**
	 * Counts one login made during the grace period.
	 *
	 * When the write keeps losing to other requests, the stored count plus
	 * this login is returned, so the caller never sees fewer logins than
	 * were made.
	 *
	 * @param int $user_id User id.
	 * @return int The count after this login.
	 */
	public static function count_grace_login( $user_id ) {
		$state = self::change_state(
			(int) $user_id,
			static function ( array $state ) {
				$state['grace_logins_used'] = ( isset( $state['grace_logins_used'] ) ? (int) $state['grace_logins_used'] : 0 ) + 1;
				return $state;
			}
		);
		return null === $state ? self::grace_logins_used( $user_id ) + 1 : (int) $state['grace_logins_used'];
	}

	/**
	 * Reads one meta row exactly as stored, for a compare-and-swap write.
	 *
	 * @param int    $user_id User id.
	 * @param string $key     Meta key.
	 * @return array|null id and raw value, or null when there is no row.
	 */
	public static function read_raw( $user_id, $key ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the stored value around the meta cache.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT umeta_id, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s ORDER BY umeta_id ASC LIMIT 1", (int) $user_id, $key ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		return array(
			'id'  => (int) $row['umeta_id'],
			'raw' => (string) $row['meta_value'],
		);
	}

	/**
	 * Writes a new value over a row from read_raw(), only if the row still
	 * holds what was read.
	 *
	 * @param int   $user_id User id.
	 * @param array $row     Result of read_raw().
	 * @param mixed $value   New value.
	 * @return bool Whether this call changed the row.
	 */
	public static function swap_raw( $user_id, array $row, $value ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One conditional UPDATE makes the swap atomic.
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->usermeta} SET meta_value = %s WHERE umeta_id = %d AND meta_value = BINARY %s", maybe_serialize( $value ), $row['id'], $row['raw'] ) );
		wp_cache_delete( (int) $user_id, 'user_meta' );
		return 1 === $changed;
	}

	/**
	 * The state meta, an array.
	 *
	 * @param int $user_id User id.
	 * @return array
	 */
	private static function state( $user_id ) {
		$state = get_user_meta( (int) $user_id, self::META_STATE, true );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Merges changes into the state meta.
	 *
	 * @param int   $user_id User id.
	 * @param array $changes Keys to set.
	 * @return void
	 */
	private static function save_state( $user_id, array $changes ) {
		self::change_state(
			$user_id,
			static function ( array $state ) use ( $changes ) {
				return array_merge( $state, $changes );
			}
		);
	}

	/**
	 * Applies a change to the state meta with a compare-and-swap write, so a
	 * parallel request's change is never lost. A write that loses the race
	 * reads the new value and tries again, three tries in all.
	 *
	 * @param int      $user_id User id.
	 * @param callable $change  Takes the stored state array, returns the new one.
	 * @return array|null The state saved, or null when every try lost.
	 */
	private static function change_state( $user_id, $change ) {
		for ( $try = 0; $try < self::STATE_TRIES; $try++ ) {
			$row = self::read_raw( $user_id, self::META_STATE );
			if ( null === $row ) {
				$state = call_user_func( $change, array() );
				// Unique, so a row a parallel request just added is not doubled; the next try swaps over it.
				if ( add_user_meta( $user_id, self::META_STATE, wp_slash( $state ), true ) ) {
					return $state;
				}
				continue;
			}

			$stored = maybe_unserialize( $row['raw'] );
			$state  = call_user_func( $change, is_array( $stored ) ? $stored : array() );
			if ( maybe_serialize( $state ) === $row['raw'] || self::swap_raw( $user_id, $row, $state ) ) {
				return $state;
			}
		}
		return null;
	}

	/**
	 * Logs a second step that passed.
	 *
	 * @param int    $user_id User id.
	 * @param string $method  app, email or backup.
	 * @return void
	 */
	public static function log_passed( $user_id, $method ) {
		AuditLog::add( 'twostep_passed', self::log_args( $user_id, __( 'Two-step login passed', 'happyaccess' ), (string) $method ) );
	}

	/**
	 * Logs a second step that failed. Pass user id 0 when no account is known.
	 *
	 * @param int    $user_id User id.
	 * @param string $method  app, email or backup.
	 * @return void
	 */
	public static function log_failed( $user_id, $method ) {
		AuditLog::add( 'twostep_failed', self::log_args( $user_id, __( 'Two-step login code did not work', 'happyaccess' ), (string) $method ) );
	}

	/**
	 * Logs a pending login cancelled after too many wrong codes.
	 *
	 * @param int    $user_id User id.
	 * @param string $method  app, email or backup.
	 * @return void
	 */
	public static function log_locked( $user_id, $method ) {
		AuditLog::add( 'twostep_locked', self::log_args( $user_id, __( 'Two-step login cancelled after too many wrong codes', 'happyaccess' ), (string) $method ) );
	}

	/**
	 * The arguments of a log entry. Never a secret or a code.
	 *
	 * @param int    $user_id User id.
	 * @param string $summary Summary.
	 * @param string $method  app or email, empty when it doesn't apply.
	 * @return array
	 */
	private static function log_args( $user_id, $summary, $method ) {
		return array(
			'feature' => 'two_step',
			'user_id' => (int) $user_id,
			'summary' => $summary,
			'meta'    => '' === $method ? array() : array( 'method' => $method ),
		);
	}
}
