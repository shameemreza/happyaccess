// Shared by the activity tests: items in the shape of GET /activity.
export const item = ( extra = {} ) => ( {
	id: 1,
	time: 1800000000,
	feature: 'support',
	event: 'settings_saved',
	event_label: 'Settings saved',
	summary: 'Saved WooCommerce shipping settings',
	actor: { id: 12, name: 'Acme Plugin Support' },
	ip: '203.0.113.24',
	token_id: 5,
	pass: 'Acme Plugin Support',
	...extra,
} );

export const page = ( items, extra = {} ) => ( {
	items,
	total: items.length,
	page: 1,
	per_page: 25,
	...extra,
} );
