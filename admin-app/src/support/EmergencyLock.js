import { useEffect, useRef, useState } from '@wordpress/element';
import { Button, Flex, Modal, Notice } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Icon, lock } from '@wordpress/icons';
import { emergencyLock } from '../api';
import { useAnnounce } from '../hooks/useAnnounce';

/**
 * The header button and the modal behind it. Ending every pass at once is
 * the one thing in the app that asks twice.
 *
 * @param {Object}                  props          Props.
 * @param {(count: number) => void} props.onLocked Called after the passes ended, with how many.
 * @return {Element} The button, and the modal while it is open.
 */
export default function EmergencyLock( { onLocked } ) {
	const announce = useAnnounce();
	const [ open, setOpen ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const mounted = useRef( true );

	useEffect( () => {
		mounted.current = true;
		return () => {
			mounted.current = false;
		};
	}, [] );

	const close = () => {
		if ( ! busy ) {
			setOpen( false );
			setError( null );
		}
	};

	const endAll = async () => {
		setBusy( true );
		setError( null );
		try {
			const result = await emergencyLock();
			const count = Number( result?.revoked ) || 0;
			announce(
				sprintf(
					/* translators: %d: number of passes that were ended. */
					_n(
						'%d pass ended',
						'%d passes ended',
						count,
						'happyaccess'
					),
					count
				)
			);
			if ( mounted.current ) {
				setOpen( false );
			}
			if ( onLocked ) {
				onLocked( count );
			}
		} catch ( e ) {
			if ( mounted.current ) {
				setError( e );
			}
		} finally {
			if ( mounted.current ) {
				setBusy( false );
			}
		}
	};

	return (
		<>
			<Button
				className="ha-lock"
				variant="secondary"
				isDestructive
				onClick={ () => setOpen( true ) }
			>
				<Icon icon={ lock } size={ 16 } />
				{ __( 'Emergency lock', 'happyaccess' ) }
			</Button>
			{ open && (
				<Modal
					title={ __( 'End every support pass now?', 'happyaccess' ) }
					onRequestClose={ close }
					shouldCloseOnClickOutside={ ! busy }
					shouldCloseOnEsc={ ! busy }
					size="medium"
				>
					<p>
						{ __(
							'Everyone using a pass is logged out and their accounts are deleted.',
							'happyaccess'
						) }
					</p>
					{ error && (
						<Notice status="error" isDismissible={ false }>
							{ error.message }
						</Notice>
					) }
					<Flex justify="flex-end" gap={ 3 }>
						<Button
							variant="secondary"
							accessibleWhenDisabled
							disabled={ busy }
							onClick={ close }
						>
							{ __( 'Keep access', 'happyaccess' ) }
						</Button>
						<Button
							variant="primary"
							isDestructive
							isBusy={ busy }
							accessibleWhenDisabled
							disabled={ busy }
							onClick={ endAll }
						>
							{ __( 'End all passes', 'happyaccess' ) }
						</Button>
					</Flex>
				</Modal>
			) }
		</>
	);
}
