/**
 * Settings as the REST route returns them.
 *
 * @param {Object} overrides Nested values to merge over the defaults.
 * @return {Object} Settings with the two derived fields.
 */
export function settingsFixture( overrides = {} ) {
	const base = {
		features: {
			support_access: true,
			passwordless: false,
			two_step: false,
		},
		security: {
			max_attempts: 5,
			attempt_window: 900,
			lockout_duration: 1800,
			site_code_cap: 30,
			proxy_header: '',
			recaptcha_enabled: false,
			recaptcha_site_key: '',
			recaptcha_threshold: 0.5,
		},
		privacy: {
			logging: true,
			retention_days: 30,
			anonymize_ip: false,
			delete_on_uninstall: false,
		},
		support: {
			default_duration: 259200,
			consent_given_at: '2026-10-01 10:00:00',
			consent_user_id: 1,
		},
		recaptcha_secret_set: false,
		needs_setup: false,
	};
	const merged = { ...base };
	Object.entries( overrides ).forEach( ( [ key, value ] ) => {
		merged[ key ] =
			value && 'object' === typeof value && ! Array.isArray( value )
				? { ...base[ key ], ...value }
				: value;
	} );
	return merged;
}
