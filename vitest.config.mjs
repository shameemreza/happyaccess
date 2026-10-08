import path from 'node:path';
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
	resolve: {
		// These two are WordPress scripts the editor loads, so they are not installed. Webpack leaves them external.
		alias: {
			'@wordpress/block-editor': path.resolve(
				'admin-app/test-stubs/block-editor.js'
			),
			'@wordpress/server-side-render': path.resolve(
				'admin-app/test-stubs/server-side-render.js'
			),
		},
	},
	test: {
		environment: 'jsdom',
		globals: false,
		restoreMocks: true,
		setupFiles: [ './vitest.setup.js' ],
		include: [ 'admin-app/**/*.test.js' ],
		css: false,
	},
} );
