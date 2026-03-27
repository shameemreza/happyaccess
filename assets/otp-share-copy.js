/**
 * OTP Share page copy-to-clipboard handler.
 *
 * @package HappyAccess
 * @since   1.0.5
 */

/* global happyaccessOtpShare */

function copyCode() {
	var code = document.getElementById( 'otp-code' ).innerText.trim();
	var btn = document.getElementById( 'copy-btn' );
	var originalText = btn.innerText;
	var copiedText = ( typeof happyaccessOtpShare !== 'undefined' && happyaccessOtpShare.copied ) ? happyaccessOtpShare.copied : 'Copied!';

	function showCopied() {
		btn.classList.add( 'copied' );
		btn.innerText = copiedText;
		setTimeout( function() {
			btn.classList.remove( 'copied' );
			btn.innerText = originalText;
		}, 2000 );
	}

	if ( navigator.clipboard && window.isSecureContext ) {
		navigator.clipboard.writeText( code ).then( showCopied );
	} else {
		var temp = document.createElement( 'textarea' );
		temp.value = code;
		document.body.appendChild( temp );
		temp.select();
		document.execCommand( 'copy' );
		document.body.removeChild( temp );
		showCopied();
	}
}
