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
