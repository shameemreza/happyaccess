<?php
/**
 * Who has two-step login, per role, for the Login and security tab.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Features\TwoStep;

use HappyAccess\Core\Internal;
use HappyAccess\Core\Settings;
use HappyAccess\Rest\Routes;

defined( 'ABSPATH' ) || exit;

/**
 * GET twostep/coverage: for each role with users on this site, how many
 * have the app or email codes on. For a required role, also how many are
 * still setting up (no method yet, grace not over or not started) and how
 * many can't skip anymore (no method, grace over).
 *
 * The counts come from user queries on the meta keys, so nothing is
 * decrypted: the app counts as on while a secret is stored, which is what
 * UserState::app_enabled() checks before it opens the secret. Temp users
 * are left out, since two-step login never applies to them.
 *
 * The answer is kept in a transient for 5 minutes. UserState drops it when
 * someone turns a method on or off, and a settings save or a role change
 * drops it too. New accounts and grace logins wait for the 5 minutes, so a
 * busy store doesn't count again on every sign-up. On a network, accounts
 * and the main site's settings are shared, so dropping it on one site
 * drops it on every site.
 *
 * On a network a super admin counts as an administrator on every site,
 * member or not, because two-step login treats them as one there
 * (Enforcement::policy()).
 *
 * The answer also says how many people use each method, for the drawing
 * of the login screen.
 */
final class Coverage {

	const TRANSIENT = 'happyaccess_twostep_coverage';

	/**
	 * Network option that gets a fresh random value each time the counts are
	 * dropped on a network. The kept counts hold the value they were made
	 * with, under GEN_KEY, so they and the value can't part ways.
	 */
	const NETWORK_GEN = 'happyaccess_twostep_coverage_gen';
	const GEN_KEY     = 'gen';

	const TTL = 300;

	const MAX_ROLES = 10;

	/**
	 * Above this many users, the tab says the counts are 5 minutes old at most.
	 */
	const LARGE_SITE = 5000;

	/**
	 * Users read at a time when checking who is past grace.
	 */
	const BATCH = 500;

	/**
	 * How the email method looks inside the stored state array.
	 */
	const EMAIL_ON = '"email";b:1;';

	/**
	 * Query var of a user query that reads only users after this id.
	 */
	const AFTER_ID = 'happyaccess_after_id';

	/**
	 * Ids of accounts being created in this request.
	 *
	 * @var array<int,bool>
	 */
	private static $new_users = array();

	/**
	 * Hooks the route and the cache clearing. Safe to call twice.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		foreach ( array( 'add_option_', 'update_option_', 'delete_option_' ) as $hook ) {
			add_action( $hook . Settings::OPTION, array( __CLASS__, 'forget' ) );
		}
		add_filter( 'insert_user_meta', array( __CLASS__, 'note_new_user' ), 10, 3 );
		add_action( 'user_register', array( __CLASS__, 'forget_new_user' ) );
		foreach ( array( 'set_user_role', 'add_user_role', 'remove_user_role' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'role_changed' ) );
		}
		foreach ( array( 'deleted_user', 'add_user_to_blog', 'remove_user_from_blog' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'forget' ) );
		}
	}

	/**
	 * Notes an account that wp_insert_user() is creating, which gets its
	 * first role next. On a busy store that happens on every sign-up and
	 * checkout, so a new account waits for the 5 minutes instead.
	 *
	 * @param array    $meta   User meta to save.
	 * @param \WP_User $user   The user.
	 * @param bool     $update Whether this is an update of an existing user.
	 * @return array
	 */
	public static function note_new_user( $meta, $user = null, $update = true ) {
		if ( ! $update && $user instanceof \WP_User ) {
			self::$new_users[ (int) $user->ID ] = true;
		}
		return $meta;
	}

	/**
	 * Forgets the note once the new account is saved.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public static function forget_new_user( $user_id ) {
		unset( self::$new_users[ (int) $user_id ] );
	}

	/**
	 * Drops the kept answer when a role changes, except the first role of
	 * an account being created.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public static function role_changed( $user_id ) {
		if ( ! isset( self::$new_users[ (int) $user_id ] ) ) {
			self::forget();
		}
	}

	/**
	 * Registers the route.
	 *
	 * @return void
	 */
	public static function routes() {
		register_rest_route(
			Routes::NS,
			'/twostep/coverage',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get' ),
				'permission_callback' => array( Routes::class, 'can_manage' ),
				'args'                => array(),
			)
		);
	}

	/**
	 * GET twostep/coverage.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get() {
		$response = new \WP_REST_Response( self::counts() );
		$response->header( 'Cache-Control', 'no-store, max-age=0' );
		return $response;
	}

	/**
	 * Drops the kept answer, and on a network the kept answer of every site.
	 *
	 * @return void
	 */
	public static function forget() {
		delete_transient( self::TRANSIENT );
		if ( ! is_multisite() ) {
			return;
		}
		// A fresh value, not the old one plus one, so two requests that drop the counts at once can't write the same value.
		$next = wp_generate_uuid4();
		// A temp user's role change lands here too, and the guards keep happyaccess_ options from them.
		Internal::run(
			static function () use ( $next ) {
				update_site_option( self::NETWORK_GEN, $next );
			}
		);
	}

	/**
	 * The counts, from the transient when it holds them.
	 *
	 * @return array{roles: array, large: bool, methods: array}
	 */
	public static function counts() {
		$kept = self::kept();
		if ( null !== $kept ) {
			return $kept;
		}
		$counts = self::compute();
		$stored = $counts;
		if ( is_multisite() ) {
			$stored[ self::GEN_KEY ] = self::network_gen();
		}
		set_transient( self::TRANSIENT, $stored, self::TTL );
		return $counts;
	}

	/**
	 * The kept answer of this site, or null when there is none or, on a
	 * network, it was dropped from another site since it was made.
	 *
	 * @return array|null
	 */
	private static function kept() {
		$kept = get_transient( self::TRANSIENT );
		if ( ! is_array( $kept ) || ! isset( $kept['roles'] ) || ! is_array( $kept['roles'] ) ) {
			return null;
		}
		if ( is_multisite() && ( ! isset( $kept[ self::GEN_KEY ] ) || self::network_gen() !== (string) $kept[ self::GEN_KEY ] ) ) {
			return null;
		}
		unset( $kept[ self::GEN_KEY ] );
		return $kept;
	}

	/**
	 * The value written the last time the counts were dropped on this
	 * network. An empty string before the first time.
	 *
	 * @return string
	 */
	private static function network_gen() {
		return (string) get_site_option( self::NETWORK_GEN, '' );
	}

	/**
	 * One role's counts: from the kept answer when it holds the role, else
	 * worked out for that role alone when $count allows it.
	 *
	 * @param string $slug  Role slug.
	 * @param bool   $count Whether to count when nothing is kept.
	 * @return array{enabled:int,total:int}|null Null when not counted or the role has no users.
	 */
	public static function role_counts( $slug, $count = true ) {
		$kept = self::kept();
		if ( null !== $kept ) {
			foreach ( $kept['roles'] as $row ) {
				if ( isset( $row['slug'], $row['enabled'], $row['total'] ) && $slug === $row['slug'] ) {
					return array(
						'enabled' => (int) $row['enabled'],
						'total'   => (int) $row['total'],
					);
				}
			}
		}
		if ( ! $count ) {
			return null;
		}
		$supers = self::super_admins();
		$total  = self::total( (string) $slug, $supers );
		if ( $total < 1 ) {
			return null;
		}
		return array(
			'enabled' => self::enabled( (string) $slug, $supers ),
			'total'   => $total,
		);
	}

	/**
	 * Whether a site has so many users that the counts may be 5 minutes old.
	 *
	 * @param int $users Users on the site.
	 * @return bool
	 */
	public static function is_large( $users ) {
		return (int) $users > self::LARGE_SITE;
	}

	/**
	 * Works the counts out: up to 10 roles with users, the biggest first.
	 *
	 * @return array{roles: array, large: bool}
	 */
	private static function compute() {
		$policy = Settings::shared( 'two_step.role_policy', array() );
		$policy = is_array( $policy ) ? $policy : array();

		$supers = self::super_admins();
		$rows   = array();
		foreach ( wp_roles()->roles as $slug => $role ) {
			$slug  = (string) $slug;
			$total = self::total( $slug, $supers );
			if ( $total < 1 ) {
				continue;
			}
			$rows[] = array(
				'slug'  => $slug,
				'name'  => self::role_name( $slug, isset( $role['name'] ) ? (string) $role['name'] : $slug ),
				'total' => $total,
			);
		}
		usort( $rows, array( __CLASS__, 'compare_rows' ) );
		$rows = array_slice( $rows, 0, self::MAX_ROLES );

		foreach ( $rows as $i => $row ) {
			$required = isset( $policy[ $row['slug'] ] ) && Enforcement::REQUIRED === $policy[ $row['slug'] ];
			$enabled  = self::enabled( $row['slug'], $supers );
			$past     = $required ? self::past_grace( $row['slug'], $supers ) : 0;

			$rows[ $i ] = array(
				'slug'       => $row['slug'],
				'name'       => $row['name'],
				'total'      => $row['total'],
				'enabled'    => $enabled,
				'required'   => $required,
				'setting_up' => $required ? max( 0, $row['total'] - $enabled - $past ) : 0,
				'past_grace' => $past,
			);
		}

		return array(
			'roles'   => $rows,
			'large'   => self::is_large( self::count( '' ) ),
			'methods' => array(
				'app'   => self::count(
					'',
					array(
						'key'     => UserState::META_TOTP,
						'compare' => 'EXISTS',
					)
				),
				'email' => self::count(
					'',
					array(
						'key'     => UserState::META_STATE,
						'value'   => self::EMAIL_ON,
						'compare' => 'LIKE',
					)
				),
			),
		);
	}

	/**
	 * On a network, the super admins, temp users left out. Empty on a
	 * single site.
	 *
	 * @return int[]
	 */
	private static function super_admins() {
		if ( ! is_multisite() ) {
			return array();
		}
		$ids = array();
		foreach ( get_super_admins() as $login ) {
			$user = get_user_by( 'login', $login );
			if ( $user instanceof \WP_User && '' === (string) get_user_meta( $user->ID, 'happyaccess_temp_user', true ) ) {
				$ids[] = (int) $user->ID;
			}
		}
		return $ids;
	}

	/**
	 * Users in a role. Super admins count as administrators only, wherever
	 * they are members and whatever role they hold here.
	 *
	 * @param string $role   Role slug.
	 * @param int[]  $supers Super admin ids.
	 * @return int
	 */
	private static function total( $role, array $supers ) {
		$total = self::count( $role, array(), $supers );
		return 'administrator' === $role ? $total + count( $supers ) : $total;
	}

	/**
	 * Users in a role with the app or email codes on, super admins counted
	 * the way total() counts them.
	 *
	 * @param string $role   Role slug.
	 * @param int[]  $supers Super admin ids.
	 * @return int
	 */
	private static function enabled( $role, array $supers ) {
		$enabled = self::count( $role, self::enabled_clause(), $supers );
		if ( 'administrator' === $role ) {
			foreach ( $supers as $id ) {
				if ( UserState::app_configured( $id ) || UserState::email_enabled( $id ) ) {
					++$enabled;
				}
			}
		}
		return $enabled;
	}

	/**
	 * Most users first, then by name.
	 *
	 * @param array $a A row.
	 * @param array $b Another row.
	 * @return int
	 */
	private static function compare_rows( $a, $b ) {
		if ( $a['total'] !== $b['total'] ) {
			return $b['total'] - $a['total'];
		}
		return strcmp( $a['name'], $b['name'] );
	}

	/**
	 * Users of this site in a role, temp users left out.
	 *
	 * @param string $role    Role slug, or empty for every user of the site.
	 * @param array  $clause  An extra meta query clause.
	 * @param int[]  $exclude User ids to leave out.
	 * @return int
	 */
	private static function count( $role, array $clause = array(), array $exclude = array() ) {
		$args = array(
			'number'        => 1,
			'fields'        => 'ID',
			'count_total'   => true,
			// Grace writes go around the meta API, so a cached query result could be stale.
			'cache_results' => false,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Counted once per role, then kept for 5 minutes.
			'meta_query'    => self::meta_query( $clause ),
		);
		if ( '' !== $role ) {
			$args['role'] = $role;
		}
		if ( $exclude ) {
			$args['exclude'] = $exclude;
		}
		return (int) ( new \WP_User_Query( $args ) )->get_total();
	}

	/**
	 * Users in a required role with no method whose grace period is over.
	 * Only users whose grace started can be past it, so only they are read,
	 * a batch at a time after the last id read, and each one is checked the
	 * way the login does.
	 *
	 * Super admins count under administrator only, each checked the same way.
	 *
	 * @param string $role   Role slug.
	 * @param int[]  $supers Super admin ids.
	 * @return int
	 */
	private static function past_grace( $role, array $supers = array() ) {
		$clause = array(
			'relation' => 'AND',
			array(
				'key'     => UserState::META_TOTP,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => UserState::META_STATE,
				'value'   => '"grace_started_at"',
				'compare' => 'LIKE',
			),
			array(
				'key'     => UserState::META_STATE,
				'value'   => self::EMAIL_ON,
				'compare' => 'NOT LIKE',
			),
		);

		$past  = 0;
		$after = 0;
		add_action( 'pre_user_query', array( __CLASS__, 'after_id' ) );
		try {
			do {
				$ids = ( new \WP_User_Query(
					array(
						'role'          => $role,
						'number'        => self::BATCH,
						'orderby'       => 'ID',
						'order'         => 'ASC',
						'fields'        => 'ID',
						'count_total'   => false,
						'cache_results' => false,
						'exclude'       => $supers,
						self::AFTER_ID  => $after,
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Only users whose grace started, then kept for 5 minutes.
						'meta_query'    => self::meta_query( $clause ),
					)
				) )->get_results();

				$ids  = array_map( 'intval', (array) $ids );
				$read = count( $ids );

				update_meta_cache( 'user', $ids );
				foreach ( $ids as $id ) {
					if ( ! Enforcement::in_grace( $id ) ) {
						++$past;
					}
					// The batch's meta is not needed again, so it doesn't pile up in memory.
					wp_cache_delete( $id, 'user_meta' );
				}
				if ( $read > 0 ) {
					$after = max( $ids );
				}
			} while ( self::BATCH === $read );
		} finally {
			remove_action( 'pre_user_query', array( __CLASS__, 'after_id' ) );
		}

		if ( 'administrator' === $role ) {
			foreach ( $supers as $id ) {
				if ( ! UserState::app_configured( $id ) && ! UserState::email_enabled( $id ) && UserState::grace_started_at( $id ) > 0 && ! Enforcement::in_grace( $id ) ) {
					++$past;
				}
			}
		}

		return $past;
	}

	/**
	 * Limits a user query that carries AFTER_ID to users after that id, so
	 * each batch is read by the primary key instead of an offset.
	 *
	 * @param \WP_User_Query $query The query.
	 * @return void
	 */
	public static function after_id( $query ) {
		global $wpdb;

		if ( ! $query instanceof \WP_User_Query || null === $query->get( self::AFTER_ID ) ) {
			return;
		}
		$query->query_where .= $wpdb->prepare( " AND {$wpdb->users}.ID > %d", (int) $query->get( self::AFTER_ID ) );
	}

	/**
	 * Users with the app or email codes on.
	 *
	 * @return array
	 */
	private static function enabled_clause() {
		return array(
			'relation' => 'OR',
			array(
				'key'     => UserState::META_TOTP,
				'compare' => 'EXISTS',
			),
			array(
				'key'     => UserState::META_STATE,
				'value'   => self::EMAIL_ON,
				'compare' => 'LIKE',
			),
		);
	}

	/**
	 * The meta query: no temp users, plus an extra clause.
	 *
	 * @param array $clause Extra clause, or none.
	 * @return array
	 */
	private static function meta_query( array $clause ) {
		$query = array(
			'relation' => 'AND',
			array(
				'key'     => 'happyaccess_temp_user',
				'compare' => 'NOT EXISTS',
			),
		);
		if ( array() !== $clause ) {
			$query[] = $clause;
		}
		return $query;
	}

	/**
	 * The name of a role as a group of people. Core and WooCommerce roles
	 * that keep their own name get a plural; any other role keeps its name.
	 *
	 * @param string $slug Role slug.
	 * @param string $name Stored role name.
	 * @return string
	 */
	private static function role_name( $slug, $name ) {
		$plurals = array(
			'administrator' => array( 'Administrator', __( 'Administrators', 'happyaccess' ) ),
			'editor'        => array( 'Editor', __( 'Editors', 'happyaccess' ) ),
			'author'        => array( 'Author', __( 'Authors', 'happyaccess' ) ),
			'contributor'   => array( 'Contributor', __( 'Contributors', 'happyaccess' ) ),
			'subscriber'    => array( 'Subscriber', __( 'Subscribers', 'happyaccess' ) ),
			'customer'      => array( 'Customer', __( 'Customers', 'happyaccess' ) ),
			'shop_manager'  => array( 'Shop manager', __( 'Shop managers', 'happyaccess' ) ),
		);
		if ( isset( $plurals[ $slug ] ) && $plurals[ $slug ][0] === $name ) {
			return $plurals[ $slug ][1];
		}
		return translate_user_role( $name );
	}
}
