import { useMemo, useState } from '@wordpress/element';
import {
	Button,
	Notice,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { __, isRTL, sprintf } from '@wordpress/i18n';
import { Icon, chevronDown, chevronLeft, chevronRight } from '@wordpress/icons';

const PRESET_NAMES = () => ( {
	administrator: __( 'Administrator', 'happyaccess' ),
	shop_manager: __( 'Shop manager', 'happyaccess' ),
	editor: __( 'Editor', 'happyaccess' ),
} );

/**
 * Picks a permission set by group, with a start-from preset and a search box.
 * It is controlled: the caller owns the ticked caps.
 *
 * @param {Object}                   props              Props.
 * @param {Object}                   props.catalog      { groups, presets } from the catalog route, or null.
 * @param {boolean}                  props.loading      Whether the catalog is loading.
 * @param {Object}                   props.error        Load error, if any.
 * @param {() => void}               props.onRetry      Loads the catalog again.
 * @param {string[]}                 props.caps         Ticked capabilities.
 * @param {string}                   props.base         Key of the selected preset.
 * @param {(key: string) => void}    props.onBaseChange Called with a preset key.
 * @param {(caps: string[]) => void} props.onCapsChange Called with the new list of caps.
 * @return {Element} The editor.
 */
export default function PermissionEditor( {
	catalog,
	loading,
	error,
	onRetry,
	caps,
	base,
	onBaseChange,
	onCapsChange,
} ) {
	const [ openId, setOpenId ] = useState( 'store' );
	const [ query, setQuery ] = useState( '' );

	const groups = catalog ? catalog.groups : null;
	const presets = catalog ? catalog.presets : null;
	const selected = useMemo( () => new Set( caps ), [ caps ] );
	const allCaps = useMemo(
		() =>
			( groups || [] ).flatMap( ( group ) =>
				group.caps.map( ( item ) => item.cap )
			),
		[ groups ]
	);

	if ( error ) {
		return (
			<div className="ha-editor">
				<Notice status="error" isDismissible={ false }>
					{ __( 'Could not load the permissions.', 'happyaccess' ) }
				</Notice>
				<Button variant="secondary" onClick={ onRetry }>
					{ __( 'Try again', 'happyaccess' ) }
				</Button>
			</div>
		);
	}
	if ( ! groups ) {
		return (
			<p className="ha-editor__loading" role="status">
				{ loading ? __( 'Loading permissions', 'happyaccess' ) : '' }
			</p>
		);
	}

	const names = PRESET_NAMES();
	const presetOptions = Object.keys( presets )
		.filter( ( key ) => names[ key ] )
		.map( ( key ) => ( { value: key, label: names[ key ] } ) );

	const commit = ( next ) =>
		onCapsChange( allCaps.filter( ( cap ) => next.has( cap ) ) );

	const search = query.trim().toLowerCase();
	const closedIcon = isRTL() ? chevronLeft : chevronRight;

	const rows = groups
		.map( ( group ) => {
			const shown = search
				? group.caps.filter(
						( item ) =>
							item.cap.toLowerCase().includes( search ) ||
							item.label.toLowerCase().includes( search )
					)
				: group.caps;
			return { group, shown };
		} )
		.filter( ( { shown } ) => shown.length > 0 );

	return (
		<div className="ha-editor">
			<div className="ha-editor__bar">
				{ presetOptions.length > 0 && (
					<SelectControl
						className="ha-editor__base"
						label={ __( 'Start from', 'happyaccess' ) }
						labelPosition="side"
						value={ base }
						options={ presetOptions }
						onChange={ onBaseChange }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				) }
				<span className="ha-editor__total">
					{ sprintf(
						/* translators: 1: permissions ticked, 2: permissions available. */
						__( '%1$d of %2$d permissions', 'happyaccess' ),
						allCaps.filter( ( cap ) => selected.has( cap ) ).length,
						allCaps.length
					) }
				</span>
			</div>
			<div className="ha-editor__search">
				<TextControl
					type="search"
					label={ __( 'Find a permission', 'happyaccess' ) }
					hideLabelFromVision
					placeholder={ __(
						'Find a permission, like refunds or plugins',
						'happyaccess'
					) }
					value={ query }
					onChange={ setQuery }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
			</div>
			<ul className="ha-groups">
				{ rows.map( ( { group, shown } ) => {
					const on = group.caps.filter( ( item ) =>
						selected.has( item.cap )
					).length;
					const all = on === group.caps.length;
					let state = 'off';
					if ( all ) {
						state = 'on';
					} else if ( on > 0 ) {
						state = 'mixed';
					}
					const open = !! search || openId === group.id;
					const listId = `ha-group-${ group.id }`;
					const toggleGroup = () => {
						const next = new Set( selected );
						group.caps.forEach( ( item ) =>
							all ? next.delete( item.cap ) : next.add( item.cap )
						);
						commit( next );
					};
					const toggleCap = ( cap ) => {
						const next = new Set( selected );
						if ( next.has( cap ) ) {
							next.delete( cap );
						} else {
							next.add( cap );
						}
						commit( next );
					};

					return (
						<li className="ha-group" key={ group.id }>
							<div className="ha-group__row">
								<button
									type="button"
									className="ha-group__toggle"
									aria-expanded={ open }
									aria-controls={ listId }
									onClick={ () =>
										setOpenId( open ? '' : group.id )
									}
								>
									<Icon
										icon={ open ? chevronDown : closedIcon }
										size={ 20 }
									/>
									<span className="ha-group__text">
										<span className="ha-group__name">
											{ group.label }
										</span>
										<span className="ha-group__hint">
											{ group.hint }
										</span>
									</span>
								</button>
								<span className="ha-group__count">
									{ sprintf(
										/* translators: 1: permissions ticked in the group, 2: permissions in the group. */
										__( '%1$d of %2$d', 'happyaccess' ),
										on,
										group.caps.length
									) }
								</span>
								<button
									type="button"
									role="switch"
									className="ha-switch"
									data-state={ state }
									aria-checked={
										'mixed' === state ? 'mixed' : all
									}
									aria-label={ group.label }
									onClick={ toggleGroup }
								>
									<span className="ha-switch__knob" />
								</button>
							</div>
							{ open && (
								<ul className="ha-caps" id={ listId }>
									{ shown.map( ( item ) => (
										<li key={ item.cap }>
											{ /* The visible text sits in nested spans, which the rule can't see. */ }
											{ /* eslint-disable-next-line jsx-a11y/label-has-associated-control */ }
											<label
												className="ha-cap"
												htmlFor={ `ha-cap-${ item.cap }` }
											>
												<input
													id={ `ha-cap-${ item.cap }` }
													type="checkbox"
													checked={ selected.has(
														item.cap
													) }
													onChange={ () =>
														toggleCap( item.cap )
													}
												/>
												<span className="ha-cap__text">
													<span className="ha-cap__label">
														{ item.label }
														{ item.trust && (
															<span className="ha-badge">
																{ __(
																	'Admin-level',
																	'happyaccess'
																) }
															</span>
														) }
													</span>
													<code className="ha-cap__code">
														{ item.cap }
													</code>
												</span>
											</label>
										</li>
									) ) }
								</ul>
							) }
						</li>
					);
				} ) }
			</ul>
			{ 0 === rows.length && (
				<p className="ha-editor__empty" role="status">
					{ __( 'No permissions match.', 'happyaccess' ) }
				</p>
			) }
			<p className="ha-editor__foot">
				{ __(
					'Always locked: HappyAccess settings and log, and your own account.',
					'happyaccess'
				) }
			</p>
		</div>
	);
}
