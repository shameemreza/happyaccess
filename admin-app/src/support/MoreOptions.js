import { useMemo } from '@wordpress/element';
import {
	CheckboxControl,
	FormTokenField,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { __, isRTL, sprintf } from '@wordpress/i18n';
import { Icon, chevronDown, chevronLeft, chevronRight } from '@wordpress/icons';

const NONE = [];

/**
 * Menu slugs and the labels the token field shows for them.
 *
 * @param {Array} menus boot.menus: top-level items with their children.
 * @return {{labels: string[], bySlug: Map, byLabel: Map}} Lookups.
 */
function buildMenuLookups( menus ) {
	const labels = [];
	const bySlug = new Map();
	const byLabel = new Map();
	const add = ( slug, label ) => {
		if ( ! slug || byLabel.has( label ) ) {
			return;
		}
		labels.push( label );
		bySlug.set( slug, label );
		byLabel.set( label, slug );
	};
	( menus || [] ).forEach( ( menu ) => {
		const parent = menu.title || menu.slug;
		add( menu.slug, parent );
		( menu.children || [] ).forEach( ( child ) => {
			add(
				child.slug,
				sprintf(
					/* translators: 1: parent menu name, 2: sub menu name. */
					__( '%1$s › %2$s', 'happyaccess' ),
					parent,
					child.title || child.slug
				)
			);
		} );
	} );
	return { labels, bySlug, byLabel };
}

/**
 * The "More options" button and the panel it opens: one-time use, role,
 * alerts, hidden screens, IP list, landing page and the email toggle.
 *
 * @param {Object}                  props       Props.
 * @param {Object}                  props.form  The form state from GrantForm.
 * @param {(patch: Object) => void} props.set   Merges a patch into the form state.
 * @param {Array}                   props.menus boot.menus, for the hidden screens field.
 * @param {Array}                   props.roles boot.roles, as { slug, name }.
 * @return {Element} The disclosure and its panel.
 */
export default function MoreOptions( {
	form,
	set,
	menus = NONE,
	roles = NONE,
} ) {
	const lookups = useMemo( () => buildMenuLookups( menus ), [ menus ] );
	const closedIcon = isRTL() ? chevronLeft : chevronRight;
	const roleOptions = [
		{
			value: 'administrator',
			label: __( 'Administrator (uses the level above)', 'happyaccess' ),
		},
		...roles
			.filter( ( item ) => 'administrator' !== item.slug )
			.map( ( item ) => ( { value: item.slug, label: item.name } ) ),
	];

	return (
		<>
			<button
				type="button"
				className="ha-disclosure"
				aria-expanded={ form.more }
				onClick={ () => set( { more: ! form.more } ) }
			>
				<Icon
					icon={ form.more ? chevronDown : closedIcon }
					size={ 20 }
				/>
				{ __( 'More options', 'happyaccess' ) }
			</button>
			{ form.more && (
				<div className="ha-more">
					<CheckboxControl
						label={ __( 'One-time use', 'happyaccess' ) }
						help={ __(
							'The link and code work for one login. That session can carry on until access ends.',
							'happyaccess'
						) }
						checked={ form.oneTime }
						onChange={ ( oneTime ) => set( { oneTime } ) }
						__nextHasNoMarginBottom
					/>
					{ 'protected' === form.level && (
						<SelectControl
							label={ __(
								'Or give a different role',
								'happyaccess'
							) }
							value={ form.role }
							options={ roleOptions }
							onChange={ ( role ) => set( { role } ) }
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
					) }
					<div className="ha-more__pair">
						<SelectControl
							label={ __( 'Login alerts', 'happyaccess' ) }
							value={ form.notify }
							options={ [
								{
									value: 'first',
									label: __( 'First login', 'happyaccess' ),
								},
								{
									value: 'every',
									label: __( 'Every login', 'happyaccess' ),
								},
								{
									value: 'off',
									label: __( 'Off', 'happyaccess' ),
								},
							] }
							onChange={ ( notify ) => set( { notify } ) }
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
						<FormTokenField
							label={ __( 'Hide admin screens', 'happyaccess' ) }
							value={ form.menus
								.map( ( slug ) => lookups.bySlug.get( slug ) )
								.filter( Boolean ) }
							suggestions={ lookups.labels }
							onChange={ ( tokens ) =>
								set( {
									menus: [
										...new Set(
											tokens
												.map( ( token ) =>
													lookups.byLabel.get(
														'string' ===
															typeof token
															? token
															: token.value
													)
												)
												.filter( Boolean )
										),
									],
								} )
							}
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						/>
					</div>
					<TextControl
						label={ __(
							'Only allow these IP addresses',
							'happyaccess'
						) }
						help={ __(
							'Separate several with commas.',
							'happyaccess'
						) }
						placeholder={ __( 'Any IP address', 'happyaccess' ) }
						value={ form.ips }
						onChange={ ( ips ) => set( { ips } ) }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<TextControl
						label={ __( 'After login, open', 'happyaccess' ) }
						help={ __(
							'A path like /wp-admin/edit.php. Leave it empty to open the dashboard.',
							'happyaccess'
						) }
						placeholder={ __( 'Dashboard', 'happyaccess' ) }
						value={ form.redirectTo }
						onChange={ ( redirectTo ) => set( { redirectTo } ) }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					{ '' !== form.email.trim() && (
						<ToggleControl
							label={ __(
								'Email it to them now',
								'happyaccess'
							) }
							checked={ form.sendEmail }
							onChange={ ( sendEmail ) => set( { sendEmail } ) }
							__nextHasNoMarginBottom
						/>
					) }
				</div>
			) }
		</>
	);
}
