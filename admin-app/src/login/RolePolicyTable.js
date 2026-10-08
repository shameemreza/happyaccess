import { createInterpolateElement, useId } from '@wordpress/element';
import { SelectControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

export const EITHER = 'either';
export const EMAIL_ONLY = 'email_only';

/**
 * @return {Array<{value: string, label: string}>} The two login choices for a role.
 */
function choices() {
	return [
		{ value: EITHER, label: __( 'Password or email code', 'happyaccess' ) },
		{ value: EMAIL_ONLY, label: __( 'Email code only', 'happyaccess' ) },
	];
}

/**
 * The warning for a role that can manage the site and may log in by email
 * code only. The constant stays out of the translated text.
 *
 * @return {Element} The warning text.
 */
function adminWarning() {
	return createInterpolateElement(
		sprintf(
			/* translators: %s: a PHP constant name. */
			__(
				'Anyone who can read this email inbox can log in as this role. If email stops working, add <code>%s</code> to wp-config.php to get back in.',
				'happyaccess'
			),
			'HAPPYACCESS_ALLOW_PASSWORD_LOGIN'
		),
		{ code: <code /> }
	);
}

/**
 * One row: the role name as the label, and its select.
 *
 * @param {Object}                  props          Props.
 * @param {Object}                  props.role     { slug, name, isAdmin }.
 * @param {string}                  props.value    The role's choice.
 * @param {(value: string) => void} props.onChange Called with the new choice.
 * @return {Element} The row.
 */
function RoleRow( { role, value, onChange } ) {
	const id = useId();
	const warn = role.isAdmin && EMAIL_ONLY === value;

	return (
		<li className="ha-roles__row">
			<label className="ha-roles__name" htmlFor={ id }>
				{ role.name }
			</label>
			<SelectControl
				id={ id }
				className="ha-roles__control"
				value={ value }
				options={ choices() }
				onChange={ onChange }
				help={
					warn ? (
						<span className="ha-roles__warning">
							{ adminWarning() }
						</span>
					) : undefined
				}
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
		</li>
	);
}

/**
 * How each role logs in: one row per role, each with "Password or email
 * code" or "Email code only". A role missing from the policy logs in either
 * way.
 *
 * @param {Object}                                props          Props.
 * @param {Array}                                 props.roles    Roles as { slug, name, isAdmin }.
 * @param {Object}                                props.policy   Role slug to choice.
 * @param {(slug: string, value: string) => void} props.onChange Called with the role and its new choice.
 * @return {Element} The list.
 */
export default function RolePolicyTable( { roles, policy, onChange } ) {
	if ( ! roles.length ) {
		return (
			<p className="ha-help">
				{ __( 'No roles found.', 'happyaccess' ) }
			</p>
		);
	}

	return (
		<ul className="ha-roles">
			{ roles.map( ( role ) => (
				<RoleRow
					key={ role.slug }
					role={ role }
					value={
						EMAIL_ONLY === policy[ role.slug ] ? EMAIL_ONLY : EITHER
					}
					onChange={ ( value ) => onChange( role.slug, value ) }
				/>
			) ) }
		</ul>
	);
}
