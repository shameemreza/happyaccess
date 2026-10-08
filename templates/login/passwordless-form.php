<?php
/**
 * Inline passwordless login form. Forms::render() fills the variables, and
 * assets/login.js runs it. It holds no form element, so it can sit inside
 * another form.
 *
 * @package HappyAccess
 *
 * @var string $form_id      Unique id prefix.
 * @var string $context      woo_account, woo_checkout, shortcode or block.
 * @var string $redirect     Validated redirect target, or empty.
 * @var string $button_class Button classes.
 * @var string $input_class  Text field class.
 * @var string $row_class    Field row classes.
 * @var string $fallback_url Request screen URL for browsers without JavaScript.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="happyaccess-pl" id="<?php echo esc_attr( $form_id ); ?>" data-happyaccess-pl data-context="<?php echo esc_attr( $context ); ?>" data-redirect="<?php echo esc_attr( $redirect ); ?>">
	<p class="happyaccess-pl__toggle-row">
		<button type="button" class="happyaccess-pl__toggle <?php echo esc_attr( $button_class ); ?>" aria-expanded="false" aria-controls="<?php echo esc_attr( $form_id . '-panel' ); ?>"><?php esc_html_e( 'Email me a login code instead', 'happyaccess' ); ?></button>
	</p>
	<div class="happyaccess-pl__panel" id="<?php echo esc_attr( $form_id . '-panel' ); ?>" hidden>
		<div class="happyaccess-pl__step" data-step="request">
			<p class="happyaccess-pl__field <?php echo esc_attr( $row_class ); ?>">
				<label for="<?php echo esc_attr( $form_id . '-login' ); ?>"><?php esc_html_e( 'Email or username', 'happyaccess' ); ?></label>
				<input type="text" id="<?php echo esc_attr( $form_id . '-login' ); ?>" class="<?php echo esc_attr( $input_class ); ?>" data-field="login" autocomplete="username" autocapitalize="off" spellcheck="false" />
			</p>
			<p class="happyaccess-pl__actions">
				<button type="button" class="<?php echo esc_attr( $button_class ); ?>" data-action="request"><?php esc_html_e( 'Send login code', 'happyaccess' ); ?></button>
			</p>
		</div>
		<div class="happyaccess-pl__step" data-step="verify" hidden>
			<p class="happyaccess-pl__field <?php echo esc_attr( $row_class ); ?>">
				<label for="<?php echo esc_attr( $form_id . '-code' ); ?>"><?php esc_html_e( 'Login code', 'happyaccess' ); ?></label>
				<input type="text" id="<?php echo esc_attr( $form_id . '-code' ); ?>" class="<?php echo esc_attr( $input_class ); ?>" data-field="code" inputmode="numeric" maxlength="12" autocomplete="one-time-code" autocapitalize="off" spellcheck="false" />
			</p>
			<p class="happyaccess-pl__remember">
				<input type="checkbox" id="<?php echo esc_attr( $form_id . '-remember' ); ?>" data-field="remember" />
				<label for="<?php echo esc_attr( $form_id . '-remember' ); ?>"><?php esc_html_e( 'Remember me', 'happyaccess' ); ?></label>
			</p>
			<p class="happyaccess-pl__actions">
				<button type="button" class="<?php echo esc_attr( $button_class ); ?>" data-action="verify"><?php esc_html_e( 'Log in', 'happyaccess' ); ?></button>
			</p>
			<p class="happyaccess-pl__restart">
				<a href="<?php echo esc_attr( '#' . $form_id . '-login' ); ?>" data-action="restart"><?php esc_html_e( 'Use a different email', 'happyaccess' ); ?></a>
			</p>
		</div>
		<div class="happyaccess-pl__message" aria-live="polite" tabindex="-1"></div>
	</div>
	<noscript>
		<p><a href="<?php echo esc_url( $fallback_url ); ?>"><?php esc_html_e( 'Email me a login code', 'happyaccess' ); ?></a></p>
	</noscript>
</div>
