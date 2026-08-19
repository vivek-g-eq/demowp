/**
 * AI Blog Writer - admin scripts.
 *
 * Handles the "Generate Post" form submission via AJAX.
 * Depends on jQuery and the localized `aibwAdmin` object
 * (ajaxUrl, nonce, i18n strings) provided by PHP.
 */
( function ( $ ) {
	'use strict';

	/**
	 * Build a notice element safely (no raw HTML injection from server strings).
	 *
	 * @param {string} type    'success' or 'error'.
	 * @param {string} message Plain text message.
	 * @param {string} [linkUrl]  Optional link URL.
	 * @param {string} [linkText] Optional link text.
	 * @return {jQuery} The notice element.
	 */
	function buildNotice( type, message, linkUrl, linkText ) {
		var $notice = $( '<div class="notice"></div>' ).addClass( 'notice-' + type );
		var $para = $( '<p></p>' ).text( message + ( linkUrl ? ' ' : '' ) );

		if ( linkUrl ) {
			$( '<a></a>' )
				.attr( 'href', linkUrl )
				.text( linkText || '' )
				.appendTo( $para );
		}

		return $notice.append( $para );
	}

	$( document ).on( 'submit', '#aibw-generate-form', function ( e ) {
		e.preventDefault();

		var $form = $( this );
		var $button = $( '#aibw-generate-btn' );
		var $spinner = $( '#aibw-spinner' );
		var $noticeArea = $( '#aibw-notice-area' );

		$noticeArea.empty();
		$button.prop( 'disabled', true );
		$spinner.addClass( 'is-active' );

		$.ajax( {
			url: aibwAdmin.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: {
				action: 'aibw_generate_post',
				nonce: aibwAdmin.nonce,
				topic: $( '#aibw-topic' ).val(),
				keywords: $( '#aibw-keywords' ).val(),
				tone: $( '#aibw-tone' ).val(),
				length: $( '#aibw-length' ).val(),
				post_status: $( '#aibw-post-status' ).val()
			}
		} )
			.done( function ( response ) {
				if ( response && response.success ) {
					$noticeArea.append(
						buildNotice( 'success', response.data.message, response.data.edit_url, aibwAdmin.i18n.editPost )
					);
					$form.trigger( 'reset' );
				} else {
					var message =
						response && response.data && response.data.message
							? response.data.message
							: aibwAdmin.i18n.error;
					$noticeArea.append( buildNotice( 'error', message ) );
				}
			} )
			.fail( function () {
				$noticeArea.append( buildNotice( 'error', aibwAdmin.i18n.error ) );
			} )
			.always( function () {
				$button.prop( 'disabled', false );
				$spinner.removeClass( 'is-active' );
			} );
	} );
} )( jQuery );
