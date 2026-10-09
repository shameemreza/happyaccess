import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { reloadPage } from './api';

/**
 * The button under an error box. It tries again, or, when HappyAccess
 * isn't answering at all, reloads the page, since a retry can't help.
 *
 * @param {Object}     props         Props.
 * @param {Object}     props.error   Normalized error, if known.
 * @param {() => void} props.onRetry Runs the failed request again.
 * @param {Object}     props.rest    Other Button props, like variant.
 * @return {Element} The button.
 */
export default function RetryButton( { error, onRetry, ...rest } ) {
	if ( 'unavailable' === error?.code ) {
		return (
			<Button { ...rest } onClick={ () => reloadPage() }>
				{ __( 'Reload the page', 'happyaccess' ) }
			</Button>
		);
	}
	return (
		<Button { ...rest } onClick={ onRetry }>
			{ __( 'Try again', 'happyaccess' ) }
		</Button>
	);
}
