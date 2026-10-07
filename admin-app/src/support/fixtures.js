// Shared by the support tests: a small catalog in the shape of GET /catalog.
export const catalog = {
	groups: [
		{
			id: 'content',
			label: 'Content',
			hint: 'Posts, pages, media and comments',
			caps: [
				{
					cap: 'edit_posts',
					label: 'Write and edit their own posts',
					trust: false,
				},
				{
					cap: 'edit_others_posts',
					label: 'Edit posts by others',
					trust: false,
				},
				{ cap: 'upload_files', label: 'Upload media', trust: false },
			],
		},
		{
			id: 'store',
			label: 'Store',
			hint: 'WooCommerce orders, products and coupons',
			caps: [
				{
					cap: 'edit_shop_orders',
					label: 'View and edit orders',
					trust: false,
				},
				{
					cap: 'view_woocommerce_reports',
					label: 'See reports and analytics',
					trust: false,
				},
			],
		},
		{
			id: 'plugins',
			label: 'Plugins and updates',
			hint: 'Turn plugins on or off, install and update',
			caps: [
				{
					cap: 'activate_plugins',
					label: 'Turn plugins on and off',
					trust: true,
				},
				{
					cap: 'install_plugins',
					label: 'Install plugins',
					trust: true,
				},
			],
		},
	],
	presets: {
		administrator: [
			'edit_posts',
			'edit_others_posts',
			'upload_files',
			'edit_shop_orders',
			'view_woocommerce_reports',
			'activate_plugins',
			'install_plugins',
		],
		shop_manager: [
			'edit_posts',
			'edit_shop_orders',
			'view_woocommerce_reports',
		],
		editor: [ 'edit_posts', 'edit_others_posts', 'upload_files' ],
	},
};

// A pass in the shape of the REST list, ending 2 days after NOW.
export const NOW = 1800000000;
export const grantFixture = ( extra = {} ) => ( {
	id: 5,
	label: 'Acme Plugin Support',
	email: '',
	level: 'protected',
	role: 'administrator',
	status: 'active',
	one_time: false,
	login_count: 3,
	use_count: 3,
	created_at: NOW - 86400,
	expires_at: NOW + 2 * 86400,
	last_login_at: NOW - 14 * 60,
	seconds_left: 2 * 86400,
	duration: 3 * 86400,
	...extra,
} );
