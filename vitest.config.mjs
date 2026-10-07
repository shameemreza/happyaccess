import { defineConfig, transformWithOxc } from 'vite';

// The app keeps JSX in .js files, as the webpack build allows. Vite needs to be told.
const jsxInJs = {
	name: 'happyaccess-jsx-in-js',
	enforce: 'pre',
	transform( code, id ) {
		if ( ! /admin-app\/.*\.js$/.test( id ) ) {
			return null;
		}
		return transformWithOxc( code, id, { lang: 'jsx', jsx: { runtime: 'automatic' } } );
	},
};

export default defineConfig( {
	plugins: [ jsxInJs ],
	test: {
		environment: 'jsdom',
		globals: false,
		restoreMocks: true,
		setupFiles: [ './vitest.setup.js' ],
		include: [ 'admin-app/**/*.test.js' ],
		css: false,
	},
} );
