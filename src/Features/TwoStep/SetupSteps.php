<?php
/**
 * Two-step setup at login, for roles that require it.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Features\TwoStep;

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Clock;
use HappyAccess\Core\Internal;
use HappyAccess\Core\Secrets;
use HappyAccess\Login\Router;

defined( 'ABSPATH' ) || exit;

/**
 * The step=twostep_setup screen. A user whose role requires two-step login
 * and who has no method lands here after the password (or after a
 * passwordless code), on the same pending login as the code step: the same
 * happyaccess_ts cookie and challenges row. The auth cookie is set only by
 * Challenge::finish(), after setup is done, or after "Later" while the grace
 * period lasts.
 *
 * The app secret waits for its first code in a transient named after the
 * pending login's key hash, encrypted with Secrets::encrypt_network() and
 * living no longer than the pending login. A transient instead of a new
 * challenges column: no schema change and no database version bump, it
 * expires by itself with the login, and an unconfirmed secret never sits in
 * a table that other code reads. The worst a lost transient (an evicted
 * object cache) costs is a new QR code to scan. After the code is
 * confirmed, the transient keeps only which method was set up, so Continue
 * works for this pending login and no other.
 */
final class SetupSteps {

	const STEP = 'twostep_setup';

	/**
	 * Prefix of the transient that holds the setup state of one pending login.
	 */
	const TRANSIENT = 'happyaccess_ts_setup_';

	const NONCE = 'happyaccess_twostep_setup';

	/**
	 * The posted field that names the action. Never "action": wp-login.php
	 * routes on $_REQUEST['action'], where a posted value wins over the query.
	 */
	const FIELD = 'ts_action';

	const HANDLE    = 'happyaccess-twostep-setup';
	const QR_HANDLE = 'happyaccess-qrcode';

	/**
	 * Version of the bundled qrcode-generator file.
	 */
	const QR_VERSION = '2.0.4';

	/**
	 * Adds the step. Safe to call twice.
	 *
	 * @return void
	 */
	public static function register() {
		Router::add_step( self::STEP, array( __CLASS__, 'run_step' ) );
	}

	/**
	 * Step callback for the setup screen.
	 *
	 * @return void
	 */
	public static function run_step() {
		// phpcs:ignore WordPress.Security.NonceVerification -- handle() verifies the nonce on every POST.
		$response = self::handle( self::request_method(), wp_unslash( $_GET ), wp_unslash( $_POST ), wp_unslash( $_COOKIE ) );
		if ( isset( $response['assets'] ) && is_array( $response['assets'] ) ) {
			self::enqueue( $response['assets'] );
		}
		Challenge::respond( $response );
	}

	/**
	 * The setup screen: the app or email form on GET, the actions on POST.
	 *
	 * @param string $method  HTTP method.
	 * @param array  $get     Unslashed $_GET.
	 * @param array  $post    Unslashed $_POST.
	 * @param array  $cookies Unslashed $_COOKIE.
	 * @return array Response for Challenge::respond().
	 */
	public static function handle( $method, array $get, array $post, array $cookies ) {
		$is_post = 'POST' === strtoupper( (string) $method );
		$input   = $is_post ? $post : $get;
		$carry   = Challenge::carry_from( $input );

		if ( ! Challenge::db_ready() ) {
			return Challenge::unavailable();
		}

		$login = Challenge::pending_login( $cookies );
		if ( null === $login ) {
			return Challenge::back_to_login( 'expired', $carry );
		}
		$user    = $login['user'];
		$pending = $login['pending'];
		$hash    = $login['hash'];
		$state   = self::state( $hash );

		$carry['method'] = 'email' === self::text( $input, 'method' ) ? 'email' : 'app';

		if ( '' !== $state['done'] ) {
			return self::handle_done( $user, $pending, $hash, $state, $carry, $is_post ? $post : null );
		}

		// Only a user who still needs setup may be here; anyone else proves a code on the code step.
		if ( ! Challenge::needs_setup( $user ) ) {
			unset( $carry['method'] );
			return array(
				'type' => 'redirect',
				'url'  => Challenge::step_url( $carry ),
			);
		}

		if ( ! $is_post ) {
			return self::screen( $user, $pending, $hash, $state, $carry, null, self::text( $get, 'sent' ) );
		}
		if ( ! self::nonce_ok( $post ) ) {
			return self::screen( $user, $pending, $hash, $state, $carry, self::expired_page() );
		}

		$action = self::text( $post, self::FIELD );
		if ( 'later' === $action ) {
			return self::handle_later( $user, $pending, $hash, $state, $carry );
		}
		if ( 'send' === $action ) {
			return self::handle_send( $user, $pending, $hash, $state, $carry );
		}
		if ( 'app' === $action ) {
			$carry['method'] = 'app';
			return self::confirm_app( $user, $pending, $hash, $state, $carry, self::text( $post, 'pwd' ) );
		}
		if ( 'email' === $action ) {
			$carry['method'] = 'email';
			return self::confirm_email( $user, $pending, $hash, $state, $carry, self::text( $post, 'pwd' ) );
		}
		return self::screen( $user, $pending, $hash, $state, $carry );
	}

	/**
	 * Adds the stylesheet and the script, and the QR library when a QR code
	 * is shown. Only the setup screen calls this.
	 *
	 * @param array $assets qr: whether the screen shows a QR code.
	 * @return void
	 */
	public static function enqueue( array $assets ) {
		wp_enqueue_style( self::HANDLE, plugins_url( 'assets/twostep-setup.css', HAPPYACCESS_PLUGIN_FILE ), array( 'login' ), self::asset_version( 'assets/twostep-setup.css' ) );
		$deps = array();
		if ( ! empty( $assets['qr'] ) ) {
			self::register_qr();
			$deps[] = self::QR_HANDLE;
		}
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/twostep-setup.js', HAPPYACCESS_PLUGIN_FILE ), $deps, self::asset_version( 'assets/twostep-setup.js' ), true );
	}

	/**
	 * Registers the bundled QR library. The profile loads it too.
	 *
	 * @return void
	 */
	public static function register_qr() {
		wp_register_script( self::QR_HANDLE, plugins_url( 'assets/vendor/qrcode.js', HAPPYACCESS_PLUGIN_FILE ), array(), self::QR_VERSION, true );
	}

	/**
	 * After setup: Continue logs in. Anything else shows the done screen,
	 * without the backup codes, which were shown once.
	 *
	 * @param \WP_User   $user    The user.
	 * @param array      $pending Pending login row.
	 * @param string     $hash    Pending login key hash.
	 * @param array      $state   Setup state.
	 * @param array      $carry   Carried values.
	 * @param array|null $post    Unslashed $_POST on a POST, else null.
	 * @return array
	 */
	private static function handle_done( \WP_User $user, array $pending, $hash, array $state, array $carry, $post ) {
		$carry['method'] = $state['done'];
		if ( null === $post || 'continue' !== self::text( $post, self::FIELD ) ) {
			return self::done_screen( $carry );
		}
		if ( ! self::nonce_ok( $post ) ) {
			return self::done_screen( $carry, self::expired_page() );
		}
		self::drop_state( $hash );
		return Challenge::finish( $user, $pending, $carry );
	}

	/**
	 * "Later": finishes the login without setup, only while the grace period lasts.
	 *
	 * @param \WP_User $user    The user.
	 * @param array    $pending Pending login row.
	 * @param string   $hash    Pending login key hash.
	 * @param array    $state   Setup state.
	 * @param array    $carry   Carried values.
	 * @return array
	 */
	private static function handle_later( \WP_User $user, array $pending, $hash, array $state, array $carry ) {
		if ( ! Enforcement::in_grace( $user->ID ) ) {
			return self::screen( $user, $pending, $hash, $state, $carry, new \WP_Error( 'happyaccess_no_grace', esc_html__( "You can't put this off anymore. Set up two-step login to finish logging in.", 'happyaccess' ) ) );
		}
		self::drop_state( $hash );
		AuditLog::add(
			'twostep_skipped',
			array(
				'feature' => 'two_step',
				'user_id' => (int) $user->ID,
				'summary' => __( 'Two-step login setup put off', 'happyaccess' ),
				'meta'    => array( 'grace' => Enforcement::grace_type() ),
			)
		);
		$carry['method'] = '';
		return Challenge::finish( $user, $pending, $carry, false );
	}

	/**
	 * Sends an email code for the email setup, within the shared send limit.
	 *
	 * @param \WP_User $user    The user.
	 * @param array    $pending Pending login row.
	 * @param string   $hash    Pending login key hash.
	 * @param array    $state   Setup state.
	 * @param array    $carry   Carried values.
	 * @return array
	 */
	private static function handle_send( \WP_User $user, array $pending, $hash, array $state, array $carry ) {
		$carry['method'] = 'email';
		$sent            = Challenge::send_email( $user, $pending['id'] );
		if ( is_wp_error( $sent ) ) {
			return self::screen( $user, $pending, $hash, $state, $carry, new \WP_Error( $sent->get_error_code(), esc_html( $sent->get_error_message() ) ) );
		}
		$carry['sent'] = 1;
		return array(
			'type' => 'redirect',
			'url'  => Challenge::step_url( $carry, self::STEP ),
		);
	}

	/**
	 * Checks the first app code. Only a right code stores the secret, which
	 * then counts as used for its time step.
	 *
	 * @param \WP_User $user    The user.
	 * @param array    $pending Pending login row.
	 * @param string   $hash    Pending login key hash.
	 * @param array    $state   Setup state.
	 * @param array    $carry   Carried values, method app.
	 * @param string   $code    Typed code.
	 * @return array
	 */
	private static function confirm_app( \WP_User $user, array $pending, $hash, array $state, array $carry, $code ) {
		$secret = self::secret( $state );
		if ( null === $secret ) {
			return self::screen( $user, $pending, $hash, $state, $carry, new \WP_Error( 'happyaccess_setup_expired', esc_html__( 'The setup key expired. Scan the new QR code and try again.', 'happyaccess' ) ) );
		}

		$attempts = Challenge::gate( $pending['id'] );
		if ( is_wp_error( $attempts ) ) {
			return self::screen( $user, $pending, $hash, $state, $carry, $attempts );
		}
		if ( $attempts < 1 ) {
			self::drop_state( $hash );
			return Challenge::back_to_login( 'expired', $carry );
		}

		$step = '' === $code ? false : Totp::match( $secret, $code, Clock::now(), 0 );
		if ( false === $step ) {
			return self::wrong( $user, $pending, $hash, $state, $carry, $attempts );
		}

		$enabled = UserState::enable_app( $user->ID, $secret );
		if ( is_wp_error( $enabled ) ) {
			return self::screen( $user, $pending, $hash, $state, $carry, new \WP_Error( $enabled->get_error_code(), esc_html( $enabled->get_error_message() ) ) );
		}
		// The code that turned the app on can't log in again on the code step.
		UserState::consume_step( $user->ID, $step );
		return self::complete( $user, $pending, $hash, $carry );
	}

	/**
	 * Checks the emailed code. Only a right code turns email codes on.
	 *
	 * @param \WP_User $user    The user.
	 * @param array    $pending Pending login row.
	 * @param string   $hash    Pending login key hash.
	 * @param array    $state   Setup state.
	 * @param array    $carry   Carried values, method email.
	 * @param string   $code    Typed code.
	 * @return array
	 */
	private static function confirm_email( \WP_User $user, array $pending, $hash, array $state, array $carry, $code ) {
		$attempts = Challenge::gate( $pending['id'] );
		if ( is_wp_error( $attempts ) ) {
			return self::screen( $user, $pending, $hash, $state, $carry, $attempts, '1' );
		}
		if ( $attempts < 1 ) {
			self::drop_state( $hash );
			return Challenge::back_to_login( 'expired', $carry );
		}
		if ( '' === $code || true !== EmailMethod::verify( $user->ID, $code ) ) {
			return self::wrong( $user, $pending, $hash, $state, $carry, $attempts );
		}
		UserState::enable_email( $user->ID );
		return self::complete( $user, $pending, $hash, $carry );
	}

	/**
	 * A wrong setup code: counted like one on the code step. The fifth one
	 * cancels the pending login and the held secret with it.
	 *
	 * @param \WP_User $user     The user.
	 * @param array    $pending  Pending login row.
	 * @param string   $hash     Pending login key hash.
	 * @param array    $state    Setup state.
	 * @param array    $carry    Carried values, with method.
	 * @param int      $attempts Checks counted on the pending login.
	 * @return array
	 */
	private static function wrong( \WP_User $user, array $pending, $hash, array $state, array $carry, $attempts ) {
		$cancelled = Challenge::wrong_code( $user, $pending, $carry, $attempts );
		if ( null !== $cancelled ) {
			self::drop_state( $hash );
			return $cancelled;
		}
		return self::screen( $user, $pending, $hash, $state, $carry, Challenge::invalid_code_error(), 'email' === $carry['method'] ? '1' : '' );
	}

	/**
	 * A method is on: makes the backup codes and shows them, once. The held
	 * secret is dropped and the state keeps only the method, for Continue.
	 *
	 * @param \WP_User $user    The user.
	 * @param array    $pending Pending login row.
	 * @param string   $hash    Pending login key hash.
	 * @param array    $carry   Carried values, with method.
	 * @return array
	 */
	private static function complete( \WP_User $user, array $pending, $hash, array $carry ) {
		self::save_state(
			$hash,
			$pending,
			array(
				'secret' => '',
				'done'   => $carry['method'],
			)
		);
		return self::codes_screen( BackupCodes::generate( $user->ID ), $carry );
	}

	/**
	 * The setup screen for the chosen method.
	 *
	 * @param \WP_User       $user    The user.
	 * @param array          $pending Pending login row.
	 * @param string         $hash    Pending login key hash.
	 * @param array          $state   Setup state.
	 * @param array          $carry   Carried values, with method.
	 * @param \WP_Error|null $errors  Errors to show, already escaped.
	 * @param string         $sent    1 when an email code was sent.
	 * @return array
	 */
	private static function screen( \WP_User $user, array $pending, $hash, array $state, array $carry, $errors = null, $sent = '' ) {
		$grace = Enforcement::in_grace( $user->ID );
		$qr    = false;

		if ( 'email' === $carry['method'] ) {
			$body = self::email_form( $carry, '' !== (string) $sent, null !== $errors );
		} else {
			$secret = self::secret( $state );
			if ( null === $secret && Secrets::is_network_persisted() ) {
				$secret = Totp::new_secret();
				self::save_state(
					$hash,
					$pending,
					array(
						'secret' => Secrets::encrypt_network( $secret ),
						'done'   => '',
					)
				);
			}
			if ( null === $secret ) {
				// A secret sealed with a key held in memory only could not be opened on the next request.
				$errors = new \WP_Error( 'happyaccess_no_site_key', esc_html__( 'The authenticator app could not be set up. Try again later.', 'happyaccess' ) );
				$body   = '';
			} else {
				$body = self::app_form( $user, $secret, $carry, null !== $errors );
				$qr   = true;
			}
		}

		$message = '<p class="message">' . esc_html( self::policy_text( $user->ID, $grace ) ) . '</p>';
		if ( '1' === (string) $sent && null === $errors ) {
			$message .= '<p class="message">' . esc_html__( 'We sent a code to your email address.', 'happyaccess' ) . '</p>';
		}

		return array(
			'type'    => 'render',
			'title'   => __( 'Set up two-step login', 'happyaccess' ),
			'body'    => $body . self::links( $carry, $grace, '' !== (string) $sent ),
			'errors'  => $errors,
			'message' => $message,
			'assets'  => array( 'qr' => $qr ),
		);
	}

	/**
	 * What the role needs and how long setup can wait.
	 *
	 * @param int  $user_id User id.
	 * @param bool $grace   Whether the grace period lasts.
	 * @return string Plain text.
	 */
	private static function policy_text( $user_id, $grace ) {
		$text = __( 'Your role needs two-step login.', 'happyaccess' ) . ' ';
		if ( ! $grace ) {
			return $text . __( 'Set it up to finish logging in.', 'happyaccess' );
		}
		if ( 'days' === Enforcement::grace_type() ) {
			return $text . sprintf(
				/* translators: %s: date the grace period ends. */
				__( 'You can skip this until %s.', 'happyaccess' ),
				wp_date( (string) get_option( 'date_format' ), Enforcement::grace_ends_at( $user_id ) )
			);
		}
		$left = Enforcement::skips_left( $user_id );
		if ( $left < 1 ) {
			return $text . __( 'This is the last time you can skip this.', 'happyaccess' );
		}
		return $text . sprintf(
			/* translators: %d: logins that can still skip setup. */
			_n( 'You can skip this %d more time.', 'You can skip this %d more times.', $left, 'happyaccess' ),
			$left
		);
	}

	/**
	 * The otpauth address an authenticator app reads, for a user and secret.
	 * The profile setup uses it too.
	 *
	 * @param \WP_User $user   The user.
	 * @param string   $secret Base32 secret.
	 * @return string
	 */
	public static function app_uri( \WP_User $user, $secret ) {
		$issuer = str_replace( ':', ' ', wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );
		if ( '' === trim( $issuer ) ) {
			$issuer = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		}
		return Totp::uri( $secret, str_replace( ':', ' ', $user->user_login ), $issuer );
	}

	/**
	 * The box the script draws the QR code into, and the setup key in
	 * groups of 4 below it. The profile prints it empty and fills it in.
	 *
	 * @param string $uri    otpauth address, or empty for the script to set.
	 * @param string $secret Base32 secret, or empty for the script to set.
	 * @return string
	 */
	public static function qr_and_key( $uri, $secret ) {
		$html  = '<div class="happyaccess-ts-qr" role="img" aria-label="' . esc_attr__( 'QR code that adds this account to your authenticator app. The setup key below does the same.', 'happyaccess' ) . '"' . ( '' === $uri ? '' : ' data-happyaccess-uri="' . esc_attr( $uri ) . '"' ) . ' hidden></div>';
		$html .= '<p class="happyaccess-ts-key-label">' . esc_html__( 'Or type this setup key into the app:', 'happyaccess' ) . '</p>';
		$html .= '<p class="happyaccess-ts-key"><code>' . esc_html( '' === $secret ? '' : implode( ' ', str_split( $secret, 4 ) ) ) . '</code></p>';
		return $html;
	}

	/**
	 * A code field. After an error it points at core's error box and takes
	 * focus, as core's login field does.
	 *
	 * @param string $id        Field id.
	 * @param string $name      Field name, or empty for a field that is never posted.
	 * @param bool   $has_error Whether an error is shown.
	 * @param string $css_class Field class.
	 * @return string
	 */
	public static function code_input( $id, $name, $has_error, $css_class = 'input' ) {
		return '<input type="text"' . ( '' === $name ? '' : ' name="' . esc_attr( $name ) . '"' ) . ' id="' . esc_attr( $id ) . '" class="' . esc_attr( $css_class ) . '" value="" size="20" maxlength="12" inputmode="numeric" autocomplete="one-time-code" autocapitalize="off" spellcheck="false"' . ( $has_error ? ' aria-describedby="login_error" autofocus' : '' ) . ' />';
	}

	/**
	 * The authenticator app form: the QR code, the key in groups of 4 and
	 * the field for the first code. The script draws the QR code into the
	 * empty box; without it the key alone still works.
	 *
	 * @param \WP_User $user      The user.
	 * @param string   $secret    Base32 secret.
	 * @param array    $carry     Carried values.
	 * @param bool     $has_error Whether an error is shown.
	 * @return string
	 */
	private static function app_form( \WP_User $user, $secret, array $carry, $has_error = false ) {
		$body  = self::form_open( 'happyaccess-ts-setup' );
		$body .= '<h2 class="happyaccess-ts-heading">' . esc_html__( 'Authenticator app', 'happyaccess' ) . '</h2>';
		$body .= '<p>' . esc_html__( 'Scan this QR code with your authenticator app.', 'happyaccess' ) . '</p>';
		$body .= self::qr_and_key( self::app_uri( $user, $secret ), $secret );
		$body .= '<p><label for="happyaccess-ts-code">' . esc_html__( 'Code from the app', 'happyaccess' ) . '</label>';
		$body .= self::code_input( 'happyaccess-ts-code', 'pwd', $has_error ) . '</p>';
		$body .= self::hidden( 'app', $carry );
		$body .= '<p class="submit"><input type="submit" class="button button-primary button-large" value="' . esc_attr__( 'Turn on two-step login', 'happyaccess' ) . '" /></p>';
		return $body . '</form>';
	}

	/**
	 * The email codes form: a button that sends a code, then the field for it.
	 *
	 * @param array $carry     Carried values.
	 * @param bool  $sent      Whether a code was sent.
	 * @param bool  $has_error Whether an error is shown.
	 * @return string
	 */
	private static function email_form( array $carry, $sent, $has_error = false ) {
		$body  = self::form_open( 'happyaccess-ts-setup' );
		$body .= '<h2 class="happyaccess-ts-heading">' . esc_html__( 'Email codes', 'happyaccess' ) . '</h2>';
		if ( ! $sent ) {
			$body .= '<p>' . esc_html__( 'We send a code to the email address on your account. Type it in here to turn on email codes.', 'happyaccess' ) . '</p>';
			$body .= self::hidden( 'send', $carry );
			$body .= '<p class="submit"><input type="submit" class="button button-primary button-large" value="' . esc_attr__( 'Email me a code', 'happyaccess' ) . '" /></p>';
			return $body . '</form>';
		}
		$body .= '<p><label for="happyaccess-ts-code">' . esc_html__( 'Email code', 'happyaccess' ) . '</label>';
		$body .= self::code_input( 'happyaccess-ts-code', 'pwd', $has_error ) . '</p>';
		$body .= self::hidden( 'email', $carry );
		$body .= '<p class="submit"><input type="submit" class="button button-primary button-large" value="' . esc_attr__( 'Turn on email codes', 'happyaccess' ) . '" /></p>';
		return $body . '</form>';
	}

	/**
	 * The links under the form, in one nav container: the other method,
	 * a new email code, and "Later" while the grace period lasts. Sending a
	 * code and "Later" change things, so they are POST buttons drawn as links,
	 * tied by their form attribute to hidden forms after the container.
	 *
	 * @param array $carry Carried values, with method.
	 * @param bool  $grace Whether the grace period lasts.
	 * @param bool  $sent  Whether an email code was sent.
	 * @return string
	 */
	private static function links( array $carry, $grace, $sent ) {
		$base = $carry;
		unset( $base['sent'] );
		$links = array();
		$forms = '';

		if ( 'email' === $carry['method'] ) {
			$links[] = '<a href="' . esc_url( Challenge::step_url( array_merge( $base, array( 'method' => 'app' ) ), self::STEP ) ) . '">' . esc_html__( 'Use an authenticator app instead', 'happyaccess' ) . '</a>';
			if ( $sent ) {
				$links[] = '<button type="submit" form="happyaccess-ts-setup-send" class="button-link">' . esc_html__( 'Send a new code', 'happyaccess' ) . '</button>';
				$forms  .= self::form_open( 'happyaccess-ts-setup-send', true ) . self::hidden( 'send', $base ) . '</form>';
			}
		} else {
			$links[] = '<a href="' . esc_url( Challenge::step_url( array_merge( $base, array( 'method' => 'email' ) ), self::STEP ) ) . '">' . esc_html__( 'Use email codes instead', 'happyaccess' ) . '</a>';
		}
		if ( $grace ) {
			$links[] = '<button type="submit" form="happyaccess-ts-later" class="button-link">' . esc_html__( 'Later', 'happyaccess' ) . '</button>';
			$forms  .= self::form_open( 'happyaccess-ts-later', true ) . self::hidden( 'later', $base ) . '</form>';
		}
		return Challenge::nav( $links ) . $forms;
	}

	/**
	 * The backup codes, shown once, with Copy, Download and the check that
	 * enables Continue. The checkbox is also required, for browsers without
	 * the script.
	 *
	 * @param string[] $codes Plain backup codes.
	 * @param array    $carry Carried values, with method.
	 * @return array
	 */
	private static function codes_screen( array $codes, array $carry ) {
		$body  = self::form_open( 'happyaccess-ts-codes-form' );
		$body .= self::codes_block( $codes, true );
		$body .= self::hidden( 'continue', $carry );
		$body .= '<p class="submit"><input type="submit" id="happyaccess-ts-continue" class="button button-primary button-large" value="' . esc_attr__( 'Continue', 'happyaccess' ) . '" /></p>';
		$body .= '</form>';

		return array(
			'type'    => 'render',
			'title'   => __( 'Set up two-step login', 'happyaccess' ),
			'body'    => $body,
			'errors'  => null,
			'message' => '<p class="message">' . esc_html__( 'Two-step login is on.', 'happyaccess' ) . '</p>',
			'assets'  => array( 'qr' => false ),
		);
	}

	/**
	 * The backup codes with Copy, Download and the "I saved these codes"
	 * check. The script enables the button with id happyaccess-ts-continue
	 * once the box is checked. The profile prints it with no codes, fills the
	 * list in and leaves the box optional, because a required box would
	 * block the profile form around it.
	 *
	 * @param string[] $codes    Plain backup codes.
	 * @param bool     $required Whether the browser requires the check.
	 * @param string   $heading  Heading element: h2 on the login screen, h3 inside the profile section.
	 * @return string
	 */
	public static function codes_block( array $codes, $required, $heading = 'h2' ) {
		$heading = 'h3' === $heading ? 'h3' : 'h2';
		$site    = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$host    = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$items   = '';
		foreach ( $codes as $code ) {
			$items .= '<li><code class="happyaccess-ts-backup">' . esc_html( substr( $code, 0, 5 ) . '-' . substr( $code, 5 ) ) . '</code></li>';
		}

		$body  = '<' . $heading . ' class="happyaccess-ts-heading" tabindex="-1">' . esc_html__( 'Save your backup codes', 'happyaccess' ) . '</' . $heading . '>';
		$body .= '<p>' . esc_html__( "Each code works once. Use one to log in when you can't get a code from your app or email.", 'happyaccess' ) . '</p>';
		$body .= '<p><strong>' . esc_html__( "These codes won't be shown again.", 'happyaccess' ) . '</strong></p>';
		$body .= '<ul class="happyaccess-ts-codes" id="happyaccess-ts-codes">' . $items . '</ul>';
		$body .= '<p class="happyaccess-ts-tools" hidden>';
		$body .= '<button type="button" class="button" data-happyaccess-copy>' . esc_html__( 'Copy', 'happyaccess' ) . '</button> ';
		$body .= '<button type="button" class="button" data-happyaccess-download data-filename="' . esc_attr( 'backup-codes-' . sanitize_file_name( '' !== $host ? $host : 'site' ) . '.txt' ) . '" data-heading="' . esc_attr(
			sprintf(
				/* translators: %s: site name. */
				__( 'Two-step login backup codes for %s', 'happyaccess' ),
				'' !== trim( $site ) ? $site : $host
			)
		) . '">' . esc_html__( 'Download as text', 'happyaccess' ) . '</button>';
		$body .= '</p>';
		$body .= '<p class="happyaccess-ts-status" role="status" data-copied="' . esc_attr__( 'Codes copied.', 'happyaccess' ) . '" data-copy-failed="' . esc_attr__( "Copy didn't work. Select the codes and copy them by hand.", 'happyaccess' ) . '"></p>';
		$body .= '<p class="happyaccess-ts-saved"><label for="happyaccess-ts-saved"><input type="checkbox"' . ( $required ? ' name="saved" value="1"' : '' ) . ' id="happyaccess-ts-saved"' . ( $required ? ' required' : '' ) . ' /> ' . esc_html__( 'I saved these codes', 'happyaccess' ) . '</label></p>';
		return $body;
	}

	/**
	 * The screen after setup when the codes were already shown.
	 *
	 * @param array          $carry  Carried values, with method.
	 * @param \WP_Error|null $errors Errors to show, already escaped.
	 * @return array
	 */
	private static function done_screen( array $carry, $errors = null ) {
		$body  = self::form_open( 'happyaccess-ts-codes-form' );
		$body .= '<p>' . sprintf(
			/* translators: %s: link to the two-step section of the profile. */
			esc_html__( "Your backup codes were shown once. If you didn't save them, make new ones on %s.", 'happyaccess' ),
			'<a href="' . esc_url( Profile::url() ) . '">' . esc_html__( 'your profile', 'happyaccess' ) . '</a>'
		) . '</p>';
		$body .= self::hidden( 'continue', $carry );
		$body .= '<p class="submit"><input type="submit" class="button button-primary button-large" value="' . esc_attr__( 'Continue', 'happyaccess' ) . '" /></p>';
		$body .= '</form>';

		return array(
			'type'    => 'render',
			'title'   => __( 'Set up two-step login', 'happyaccess' ),
			'body'    => $body,
			'errors'  => $errors,
			'message' => '<p class="message">' . esc_html__( 'Two-step login is on.', 'happyaccess' ) . '</p>',
			'assets'  => array( 'qr' => false ),
		);
	}

	/**
	 * The opening tag of a POST form to this step.
	 *
	 * @param string $id     Form id.
	 * @param bool   $hidden Whether the form is hidden, for a button elsewhere.
	 * @return string
	 */
	private static function form_open( $id, $hidden = false ) {
		return '<form id="' . esc_attr( $id ) . '" class="happyaccess-ts-form" method="post" action="' . esc_url( Router::url( self::STEP ) ) . '"' . ( $hidden ? ' hidden' : '' ) . '>';
	}

	/**
	 * The hidden fields of a setup form: the action, the method, the
	 * carried values and the nonce.
	 *
	 * @param string $action Action.
	 * @param array  $carry  Carried values, with method.
	 * @return string
	 */
	private static function hidden( $action, array $carry ) {
		$html  = '<input type="hidden" name="' . esc_attr( self::FIELD ) . '" value="' . esc_attr( $action ) . '" />';
		$html .= '<input type="hidden" name="method" value="' . esc_attr( isset( $carry['method'] ) ? $carry['method'] : 'app' ) . '" />';
		$html .= Challenge::carry_fields( $carry );
		return $html . Challenge::nonce_input( self::NONCE );
	}

	/**
	 * The setup state of a pending login.
	 *
	 * @param string $hash Pending login key hash.
	 * @return array secret (encrypted, or empty) and done (app, email or empty).
	 */
	private static function state( $hash ) {
		$name  = self::TRANSIENT . $hash;
		$state = Internal::run(
			static function () use ( $name ) {
				return get_transient( $name );
			}
		);
		$state = is_array( $state ) ? $state : array();
		return array(
			'secret' => isset( $state['secret'] ) && is_string( $state['secret'] ) ? $state['secret'] : '',
			'done'   => isset( $state['done'] ) && in_array( $state['done'], array( 'app', 'email' ), true ) ? $state['done'] : '',
		);
	}

	/**
	 * Saves the setup state until the pending login expires.
	 *
	 * @param string $hash    Pending login key hash.
	 * @param array  $pending Pending login row.
	 * @param array  $state   secret and done.
	 * @return void
	 */
	private static function save_state( $hash, array $pending, array $state ) {
		$name = self::TRANSIENT . $hash;
		$ttl  = max( 1, (int) $pending['created_at'] + Challenge::LIFETIME - Clock::now() );
		Internal::run(
			static function () use ( $name, $state, $ttl ) {
				set_transient( $name, $state, $ttl );
			}
		);
	}

	/**
	 * Removes the setup state.
	 *
	 * @param string $hash Pending login key hash.
	 * @return void
	 */
	private static function drop_state( $hash ) {
		$name = self::TRANSIENT . $hash;
		Internal::run(
			static function () use ( $name ) {
				delete_transient( $name );
			}
		);
	}

	/**
	 * The held app secret, or null.
	 *
	 * @param array $state Setup state.
	 * @return string|null
	 */
	private static function secret( array $state ) {
		if ( '' === $state['secret'] ) {
			return null;
		}
		$secret = Secrets::decrypt_network( $state['secret'] );
		return ( null === $secret || '' === $secret ) ? null : $secret;
	}

	/**
	 * The error for a form with a stale nonce.
	 *
	 * @return \WP_Error
	 */
	private static function expired_page() {
		return new \WP_Error( 'expired_page', esc_html__( 'This page expired. Try again.', 'happyaccess' ) );
	}

	/**
	 * Verifies the setup nonce.
	 *
	 * @param array $post Unslashed $_POST.
	 * @return bool
	 */
	private static function nonce_ok( array $post ) {
		$nonce = self::text( $post, '_wpnonce' );
		return '' !== $nonce && false !== wp_verify_nonce( $nonce, self::NONCE );
	}

	/**
	 * A request value as a string. Arrays and other types become empty.
	 *
	 * @param array  $source Request array.
	 * @param string $key    Key.
	 * @return string
	 */
	private static function text( array $source, $key ) {
		return isset( $source[ $key ] ) && is_string( $source[ $key ] ) ? trim( $source[ $key ] ) : '';
	}

	/**
	 * Request method, upper-case.
	 *
	 * @return string
	 */
	private static function request_method() {
		return isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	}

	/**
	 * Asset version: the plugin version, plus the file time while debugging.
	 * The profile uses it too.
	 *
	 * @param string $path Path inside the plugin.
	 * @return string
	 */
	public static function asset_version( $path ) {
		$file = HAPPYACCESS_PLUGIN_DIR . $path;
		return HAPPYACCESS_VERSION . ( WP_DEBUG && is_readable( $file ) ? '.' . filemtime( $file ) : '' );
	}
}
