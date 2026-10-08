<?php
/**
 * Server render for the HappyAccess Login block.
 *
 * @package HappyAccess
 *
 * @var array $attributes Block attributes: redirectTo and toggleStyle.
 */

use HappyAccess\Features\Passwordless\Feature;
use HappyAccess\Features\Passwordless\Forms;

defined( 'ABSPATH' ) || exit;

$happyaccess_preview = Feature::is_editor_preview();

// A logged-in visitor needs no login form. The editor preview is the one exception.
if ( is_user_logged_in() && ! $happyaccess_preview ) {
	return;
}

$happyaccess_html = Forms::render(
	array(
		'redirect_to' => isset( $attributes['redirectTo'] ) && is_string( $attributes['redirectTo'] ) ? $attributes['redirectTo'] : '',
		'context'     => 'block',
		'style'       => isset( $attributes['toggleStyle'] ) && is_string( $attributes['toggleStyle'] ) ? $attributes['toggleStyle'] : '',
	)
);

if ( '' === $happyaccess_html ) {
	return;
}

if ( $happyaccess_preview ) {
	$happyaccess_html = Feature::preview_markup( $happyaccess_html );
}

printf(
	'<div %1$s>%2$s</div>',
	get_block_wrapper_attributes(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core escapes the attributes.
	$happyaccess_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The form template escapes every value.
);
