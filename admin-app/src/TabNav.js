import { __ } from '@wordpress/i18n';
import { tabUrl } from './tabList';

/**
 * @param {Object}                 props          Props.
 * @param {Array}                  props.tabs     Tabs to show.
 * @param {string}                 props.current  Current tab slug.
 * @param {(slug: string) => void} props.onSelect Called with a slug when a tab is chosen.
 * @return {Element} The tab links.
 */
export default function TabNav( { tabs, current, onSelect } ) {
	return (
		<nav
			className="ha-tabs"
			aria-label={ __( 'HappyAccess sections', 'happyaccess' ) }
		>
			{ tabs.map( ( tab ) => (
				<a
					key={ tab.slug }
					className="ha-tab"
					href={ tabUrl( tab.slug ) }
					aria-current={ tab.slug === current ? 'page' : undefined }
					onClick={ ( event ) => {
						event.preventDefault();
						onSelect( tab.slug );
					} }
				>
					{ tab.label }
				</a>
			) ) }
		</nav>
	);
}
