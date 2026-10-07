import { useEffect, useRef, useState } from '@wordpress/element';
import { Button, Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { Icon, check, info } from '@wordpress/icons';
import { useAnnounce } from '../hooks/useAnnounce';
import { formatEnd, levelName } from './passFormat';

const COPIED_MS = 2200;

function hostOf( url ) {
	try {
		return new URL( url ).host;
	} catch {
		return window.location.host;
	}
}

/**
 * A Copy button that says "Copied" for a moment.
 *
 * @param {Object}     props           Props.
 * @param {boolean}    props.copied    Whether it just copied.
 * @param {string}     props.label     What the button copies, for its accessible name.
 * @param {string}     props.doneLabel Accessible name while it says Copied.
 * @param {() => void} props.onClick   Copies.
 * @return {Element} The button.
 */
function CopyButton( { copied, label, doneLabel, onClick } ) {
	return (
		<Button
			className={ copied ? 'ha-copy is-copied' : 'ha-copy' }
			variant="secondary"
			aria-label={ copied ? doneLabel : label }
			onClick={ onClick }
		>
			{ copied
				? __( 'Copied', 'happyaccess' )
				: __( 'Copy', 'happyaccess' ) }
		</Button>
	);
}

/**
 * The card that replaces the form after a pass is made: the pass itself, the
 * link, the code and the ways to hand them over. The secrets in `result` live
 * only as long as the parent keeps them.
 *
 * @param {Object}                props             Props.
 * @param {Object}                props.result      The create or regenerate response.
 * @param {Object}                props.boot        Boot data: roles and loginUrl.
 * @param {() => void}            props.onDone      Called when the person is finished.
 * @param {() => Promise<Object>} props.onSendEmail Makes new secrets and emails them. Resolves with the new response.
 * @return {Element} The card.
 */
export default function ResultCard( {
	result,
	boot = {},
	onDone,
	onSendEmail,
} ) {
	const announce = useAnnounce();
	const heading = useRef( null );
	const linkInput = useRef( null );
	const codeText = useRef( null );
	const messageText = useRef( null );
	const emailButton = useRef( null );
	const timer = useRef( null );
	const [ copied, setCopied ] = useState( '' );
	const [ manual, setManual ] = useState( '' );
	const [ showMessage, setShowMessage ] = useState( false );
	const [ asking, setAsking ] = useState( false );
	const [ sending, setSending ] = useState( false );
	const [ sentTo, setSentTo ] = useState( '' );
	const [ error, setError ] = useState( null );
	const mounted = useRef( true );

	useEffect( () => {
		mounted.current = true;
		heading.current?.focus();
		return () => {
			mounted.current = false;
			clearTimeout( timer.current );
		};
	}, [] );

	// A manual copy hint belongs to the secret it was shown for.
	useEffect( () => {
		setManual( '' );
		setCopied( '' );
	}, [ result.code, result.link_url ] );

	const selectNode = ( node ) => {
		const selection = node?.ownerDocument.defaultView.getSelection();
		if ( selection ) {
			selection.removeAllRanges();
			const range = node.ownerDocument.createRange();
			range.selectNodeContents( node );
			selection.addRange( range );
		}
	};

	const copy = async ( key, text, announced ) => {
		let done = false;
		if ( navigator.clipboard?.writeText ) {
			try {
				await navigator.clipboard.writeText( text );
				done = true;
			} catch {
				done = false;
			}
		}
		if ( ! mounted.current ) {
			return;
		}
		clearTimeout( timer.current );
		if ( done ) {
			setManual( '' );
			setCopied( key );
			announce( announced );
			timer.current = setTimeout( () => setCopied( '' ), COPIED_MS );
			return;
		}
		// No clipboard access: select the text so Ctrl+C works.
		setCopied( '' );
		setManual( key );
		if ( 'link' === key ) {
			linkInput.current?.select();
		} else if ( 'code' === key ) {
			selectNode( codeText.current );
		} else {
			setShowMessage( true );
			// The message mounts on the next render.
			setTimeout( () => selectNode( messageText.current ), 0 );
		}
	};

	const send = async () => {
		setSending( true );
		setError( null );
		try {
			const next = await onSendEmail();
			if ( ! mounted.current ) {
				return;
			}
			setAsking( false );
			if ( next.emailed ) {
				setSentTo( result.email );
				announce(
					sprintf(
						/* translators: %s: the email address. */
						__( 'New link and code emailed to %s', 'happyaccess' ),
						result.email
					)
				);
			} else {
				setSentTo( '' );
				setError( {
					message: __(
						'The email could not be sent. The new link and code are shown here.',
						'happyaccess'
					),
				} );
			}
			setTimeout( () => emailButton.current?.focus(), 0 );
		} catch ( e ) {
			if ( mounted.current ) {
				setError( e );
			}
		} finally {
			if ( mounted.current ) {
				setSending( false );
			}
		}
	};

	const hint = ( key ) =>
		manual === key && (
			<span className="ha-manual">
				{ __( 'Press Ctrl+C to copy', 'happyaccess' ) }
			</span>
		);

	const ends = formatEnd( result.expires_at );
	const level = levelName( result, boot.roles );
	const host = hostOf( boot.loginUrl || result.code_url );

	return (
		<section
			className="ha-grant ha-result"
			aria-labelledby="ha-result-title"
		>
			<div className="ha-result__head">
				<span className="ha-result__tick" aria-hidden="true">
					<Icon icon={ check } size={ 24 } />
				</span>
				<div>
					<h2 id="ha-result-title" ref={ heading } tabIndex={ -1 }>
						{ sprintf(
							/* translators: %s: who the pass is for. */
							__( 'Access is ready for %s', 'happyaccess' ),
							result.label
						) }
					</h2>
					<p>
						{ sprintf(
							/* translators: %s: date and time the pass ends. */
							__(
								'Ends %s. Give them the link or the code, either one works.',
								'happyaccess'
							),
							ends
						) }
					</p>
				</div>
			</div>

			<div className="ha-result__body">
				<div
					className="ha-preview ha-pass"
					role="group"
					aria-label={ __( 'Support pass', 'happyaccess' ) }
				>
					<div className="ha-preview__top">
						<div className="ha-preview__meta">
							<span className="ha-preview__tag">
								{ __( 'Support pass', 'happyaccess' ) }
							</span>
							<span className="ha-preview__until">
								{ sprintf(
									/* translators: %s: date and time the pass ends. */
									__( 'Valid until %s', 'happyaccess' ),
									ends
								) }
							</span>
						</div>
						<div className="ha-preview__label">
							{ result.label }
						</div>
						<div className="ha-preview__level">{ level }</div>
						<label className="ha-pass__name" htmlFor="ha-link">
							{ __( 'Login link', 'happyaccess' ) }
						</label>
						<div className="ha-pass__row">
							<input
								ref={ linkInput }
								id="ha-link"
								className="ha-pass__link"
								type="text"
								readOnly
								value={ result.link_url }
								onFocus={ ( event ) => event.target.select() }
							/>
							<CopyButton
								copied={ 'link' === copied }
								label={ __( 'Copy login link', 'happyaccess' ) }
								doneLabel={ __(
									'Login link copied',
									'happyaccess'
								) }
								onClick={ () =>
									copy(
										'link',
										result.link_url,
										__( 'Login link copied', 'happyaccess' )
									)
								}
							/>
						</div>
						{ hint( 'link' ) }
					</div>
					<div className="ha-preview__cut" aria-hidden="true" />
					<div className="ha-preview__bottom ha-pass__code">
						<div className="ha-pass__codebox">
							<div className="ha-pass__name" id="ha-code-name">
								{ __( 'Or the access code', 'happyaccess' ) }
							</div>
							<div
								ref={ codeText }
								className="ha-pass__digits"
								aria-labelledby="ha-code-name"
							>
								{ result.code }
							</div>
							<div className="ha-help">
								{ sprintf(
									/* translators: 1: the site's login address, like example.com. 2: the label of the link on the login screen. */
									__(
										'Entered at %1$s, "%2$s"',
										'happyaccess'
									),
									host,
									__(
										'Have a support access code?',
										'happyaccess'
									)
								) }
							</div>
							{ hint( 'code' ) }
						</div>
						<CopyButton
							copied={ 'code' === copied }
							label={ __( 'Copy access code', 'happyaccess' ) }
							doneLabel={ __(
								'Access code copied',
								'happyaccess'
							) }
							onClick={ () =>
								copy(
									'code',
									result.code,
									__( 'Access code copied', 'happyaccess' )
								)
							}
						/>
					</div>
				</div>

				<Button
					className="ha-result__toggle"
					variant="link"
					aria-expanded={ showMessage }
					onClick={ () => setShowMessage( ! showMessage ) }
				>
					{ showMessage
						? __( 'Hide the message', 'happyaccess' )
						: __( 'See the message they get', 'happyaccess' ) }
				</Button>
				{ showMessage && (
					<pre ref={ messageText } className="ha-result__message">
						{ result.message }
					</pre>
				) }

				<div className="ha-result__buttons">
					<Button
						className="ha-result__primary"
						variant="primary"
						onClick={ () =>
							copy(
								'message',
								result.message,
								__(
									'Message copied, ready to paste',
									'happyaccess'
								)
							)
						}
					>
						{ 'message' === copied
							? __( 'Copied, ready to paste', 'happyaccess' )
							: __(
									'Copy message with instructions',
									'happyaccess'
								) }
					</Button>
					{ result.email && (
						<Button
							ref={ emailButton }
							variant="secondary"
							accessibleWhenDisabled
							disabled={ sending }
							aria-expanded={ asking }
							onClick={ () => {
								setError( null );
								setAsking( ! asking );
							} }
						>
							{ __( 'Send by email', 'happyaccess' ) }
						</Button>
					) }
				</div>
				{ hint( 'message' ) }

				{ asking && (
					<div className="ha-confirm" role="alert">
						<span className="ha-confirm__text">
							{ __(
								'This makes a new link and code and emails them. The ones shown here stop working.',
								'happyaccess'
							) }
						</span>
						<Button
							variant="secondary"
							size="compact"
							accessibleWhenDisabled
							disabled={ sending }
							onClick={ () => {
								setAsking( false );
								emailButton.current?.focus();
							} }
						>
							{ __( 'Cancel', 'happyaccess' ) }
						</Button>
						<Button
							variant="primary"
							size="compact"
							isBusy={ sending }
							accessibleWhenDisabled
							disabled={ sending }
							onClick={ send }
						>
							{ __( 'Make new ones and send', 'happyaccess' ) }
						</Button>
					</div>
				) }
				{ sentTo && (
					<p className="ha-result__sent">
						{ sprintf(
							/* translators: %s: the email address. */
							__(
								'Emailed to %s. The link and code shown here are the new ones.',
								'happyaccess'
							),
							sentTo
						) }
					</p>
				) }
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error.message }
					</Notice>
				) }

				<div className="ha-result__note" role="note">
					<Icon icon={ info } size={ 20 } />
					<span>
						{ __(
							"Anyone with the link or the code can log in. For extra safety, send them through different channels. You won't see the code again, but you can make a new one any time.",
							'happyaccess'
						) }
					</span>
				</div>

				<Button
					className="ha-result__done"
					variant="link"
					onClick={ onDone }
				>
					{ __( 'Done, give access to someone else', 'happyaccess' ) }
				</Button>
			</div>
		</section>
	);
}
