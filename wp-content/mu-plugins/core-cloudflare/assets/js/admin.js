/**
 * Core Cloudflare - Admin JS
 *
 * Handles all AJAX interactions for the Network Admin UI. Every request
 * includes the shared nonce (CoreCloudflare.nonce) which the server
 * verifies via check_ajax_referer() on every handler.
 */
/* global jQuery, CoreCloudflare */
( function ( $ ) {
	'use strict';

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	function renderSummary( $target, summary ) {
		var isOk = summary.failure_count === 0;
		var envLabel = getEnvironmentLabel();
		var html = '<div class="cc-summary cc-summary-' + ( isOk ? 'success' : 'warning' ) + '">';

		html += '<div class="cc-summary-stats">';
		html += '<strong>' + summary.success_count + ' ' + escapeHtml( CoreCloudflare.i18n.labelSuccess ) + '</strong>';
		html += '<strong>' + summary.failure_count + ' ' + escapeHtml( CoreCloudflare.i18n.labelFailed ) + '</strong>';
		html += '<span>' + escapeHtml( CoreCloudflare.i18n.labelExecTime ) + ': ' + Number( summary.execution_time ).toFixed( 3 ) + 's</span>';
		html += '</div>';

		html += '<div class="' + ( isOk ? 'cc-purge-success-message' : 'cc-purge-error-message' ) + '">';
		html += '<strong>' + ( isOk ? '✓ Cache Cleared' : ( summary.success_count > 0 ? '⚠ Purge Completed with Errors' : '✕ Cache Purge Failed' ) ) + '</strong>';
		html += '<span>' + escapeHtml( envLabel + ': ' + ( summary.note || ( isOk ? 'Cache cleared successfully.' : 'The purge could not be completed for one or more domains.' ) ) ) + '</span>';
		html += '</div>';

		if ( summary.successful_domains && summary.successful_domains.length ) {
			html += '<div class="cc-result-section cc-result-success"><strong>' + escapeHtml( CoreCloudflare.i18n.labelSuccessful ) + '</strong><ul>';
			$.each( summary.successful_domains, function ( _, domain ) {
				html += '<li>' + escapeHtml( domain ) + '</li>';
			} );
			html += '</ul></div>';
		}

		if ( summary.failed_domains && Object.keys( summary.failed_domains ).length ) {
			html += '<div class="cc-result-section cc-result-failure"><strong>' + escapeHtml( CoreCloudflare.i18n.labelFailed ) + '</strong><ul>';
			$.each( summary.failed_domains, function ( domain, error ) {
				html += '<li><strong>' + escapeHtml( domain ) + '</strong>: ' + escapeHtml( error ) + '</li>';
			} );
			html += '</ul></div>';
		}

		if ( summary.unknown_domains && summary.unknown_domains.length ) {
			html += '<div class="cc-result-section cc-result-failure"><strong>Cloudflare zone mismatch</strong><ul>';
			$.each( summary.unknown_domains, function ( _, domain ) {
				html += '<li><strong>' + escapeHtml( domain ) + '</strong>: ' + escapeHtml( envLabel + ': This domain does not match an accessible Cloudflare zone. Check the hostname and token Zone Read permission.' ) + '</li>';
			} );
			html += '</ul></div>';
		}

		html += '</div>';
		$target.html( html );
	}

	function escapeHtml( value ) {
		return $( '<div>' ).text( value == null ? '' : String( value ) ).html();
	}

	function updateSelectedCount() {
		var count = $( '.cc-domain-checkbox:checked' ).length;
		$( '#cc-selected-count' ).text( count + ' selected' );
	}

	function renderMessage( $target, message, isError ) {
		$target.html(
			'<div class="notice notice-' + ( isError ? 'error' : 'success' ) + ' inline"><p>' + escapeHtml( message ) + '</p></div>'
		);
	}

	function renderErrorList( $target, errors ) {
		var $list = $('<ul class="cc-error-list"></ul>');
		$.each(errors, function (_, error) {
			$('<li></li>').text(error).appendTo($list);
		});
		$target.append($list);
	}

	function clearSettingsFieldErrors() {
		$( '.cc-field-error' ).empty();
		$( '#cc-api-token, #cc-account-id, #cc-staging-domains, #cc-production-domains' ).removeClass( 'cc-field-invalid' ).attr( 'aria-invalid', 'false' );
	}

	function renderSettingsFieldErrors( fieldErrors ) {
		clearSettingsFieldErrors();
		$.each( fieldErrors || {}, function ( field, errors ) {
			if ( ! errors || ! errors.length ) { return; }
			var $target = $( '#cc-' + field.replace( '_', '-' ) + '-error' );
			if ( $target.length ) {
				$.each( errors, function ( _, error ) {
					$('<div></div>').text( error ).appendTo( $target );
				} );
			}
			$( '#cc-' + field.replace( '_', '-' ) ).addClass( 'cc-field-invalid' ).attr( 'aria-invalid', 'true' );
		} );
	}

	function getEnvironmentLabel() {
		var env = $( '#cc-environment' ).val() || ( CoreCloudflare.environment || 'staging' );
		return env === 'production' ? 'Live' : 'Staging';
	}

	function getAjaxErrorMessage( xhr, fallback ) {
		var label = getEnvironmentLabel();
		var message = '';

		if ( xhr && xhr.responseJSON && xhr.responseJSON.data ) {
			message = xhr.responseJSON.data.message || xhr.responseJSON.data.error || '';
		}

		if ( ! message && xhr && xhr.responseText ) {
			try {
				var payload = JSON.parse( xhr.responseText );
				if ( payload && payload.data ) {
					message = payload.data.message || payload.data.error || '';
				}
			} catch ( e ) {}
		}

		if ( message ) {
			return label + ': ' + message;
		}

		if ( xhr && xhr.status === 401 ) {
			return label + ': You are not authorized to perform this action.';
		}
		if ( xhr && xhr.status === 403 ) {
			return label + ': You do not have permission to perform this action.';
		}
		if ( xhr && xhr.status === 404 ) {
			return label + ': The requested admin endpoint was not found.';
		}
		if ( xhr && xhr.status >= 500 ) {
			return label + ': WordPress returned a server error while processing the request. Check the PHP/WordPress error log for the exact error.';
		}
		if ( xhr && xhr.status === 0 ) {
			return label + ': The browser could not connect to WordPress. Check the site URL, SSL, firewall, or network connection.';
		}

		return label + ': ' + ( fallback || 'The request could not be completed.' );
	}

	function renderWarningList( $target, warnings ) {
		var $notice = $('<div class="notice notice-warning inline"><p></p></div>');
		$notice.find('p').text('Configuration notice:');

		var $list = $('<ul></ul>');
		$.each(warnings, function (_, warning) {
			$('<li></li>').text(warning).appendTo($list);
		});

		$notice.append($list);
		$target.append($notice);
	}

	// -------------------------------------------------------------------------
	// Cache Purge tab
	// -------------------------------------------------------------------------

	function doPurge( scope, domains ) {
		var $progress = $( '#cc-purge-progress' );
		var $result   = $( '#cc-purge-result' );

		$progress.show();
		$result.empty();

		$.post( CoreCloudflare.ajaxUrl, {
			action:  'core_cloudflare_purge',
			nonce:   CoreCloudflare.nonce,
			scope:   scope,
			domains: domains || [],
		} )
			.done( function ( response ) {
				$progress.hide();
				if ( response.success ) {
					renderSummary( $result, response.data );
				} else {
					renderMessage( $result, response.data.message || CoreCloudflare.i18n.errorPurgeFailed, true );
				}
			} )
			.fail( function ( xhr ) {
				$progress.hide();
				renderMessage( $result, getAjaxErrorMessage( xhr, CoreCloudflare.i18n.errorRequestFailed ), true );
			} );
	}

	// -------------------------------------------------------------------------
	// Configuration tab: show only the domain row for the active environment
	// -------------------------------------------------------------------------

	function toggleDomainRows() {
		var env = $( '#cc-environment' ).val();
		$( '#cc-row-staging-domains' ).toggle( env === 'staging' );
		$( '#cc-row-production-domains' ).toggle( env === 'production' );
	}

	function updateCredentialUi(env) {
		var envLabel = env === 'production' ? 'Live' : 'Staging';
		var deleteBtn = $( '#cc-delete-credentials' );
		if ( deleteBtn.length ) {
			deleteBtn.text( 'Delete ' + envLabel + ' Credentials' );
		}
	}

	function updateCredentialFields( hasToken, hasAccountId, maskedToken, maskedAccount ) {
		$( '#cc-token-display' ).toggleClass( 'is-hidden', ! hasToken );
		$( '#cc-api-token' ).toggleClass( 'is-hidden', hasToken );
		$( '#cc-token-masked' ).text( maskedToken || '(not configured)' );
		if ( hasToken ) { $( '#cc-api-token' ).val( '' ); }
		$( '#cc-account-display' ).toggleClass( 'is-hidden', ! hasAccountId );
		$( '#cc-account-id' ).toggleClass( 'is-hidden', hasAccountId );
		$( '#cc-account-id-masked' ).text( maskedAccount || '(not configured)' );
		if ( hasAccountId ) { $( '#cc-account-id' ).val( '' ); }
	}

	function syncCredentialsForEnvironment( env ) {
		var raw = $( '#cc-settings-form' ).attr( 'data-credentials' ) || '{}';
		var credentials = {};
		try { credentials = JSON.parse( raw ); } catch ( e ) {}
		var current = credentials[ env ] || {};
		updateCredentialFields( !! current.has_token, !! current.has_account_id, current.masked_token, current.masked_account );
	}

	// -------------------------------------------------------------------------
	// Boot
	// -------------------------------------------------------------------------

	$( function () {

		// --- Cache Purge tab ---------------------------------------------------

		$( '#cc-purge-all' ).on( 'click', function () {
			if ( ! window.confirm( CoreCloudflare.i18n.confirmPurgeAll ) ) {
				return;
			}
			doPurge( 'all' );
		} );

		$( document ).on( 'click', '.cc-purge-site', function () {
			var $button = $( this );
			var domain = $button.data( 'domain' );

			if ( ! domain || ! window.confirm( 'Purge Cloudflare cache for ' + domain + '?' ) ) {
				return;
			}

			$button.prop( 'disabled', true ).text( 'Purging…' );
			doPurge( 'selected', [ domain ] );
			$( document ).one( 'ajaxStop', function () {
				$button.prop( 'disabled', false ).text( 'Purge This Site' );
			} );
		} );

		$( '#cc-purge-selected' ).on( 'click', function () {
			var domains = $( '.cc-domain-checkbox:checked' )
				.map( function () { return $( this ).val(); } )
				.get();

			if ( ! domains.length ) {
				window.alert( CoreCloudflare.i18n.errorNoDomainSelected );
				return;
			}

			if ( ! window.confirm( CoreCloudflare.i18n.confirmPurgeSelected ) ) {
				return;
			}

			doPurge( 'selected', domains );
		} );

		$( '#cc-select-all-domains' ).on( 'change', function () {
			$( '.cc-domain-checkbox' ).prop( 'checked', $( this ).is( ':checked' ) );
			updateSelectedCount();
		} );

		$( document ).on( 'change', '.cc-domain-checkbox', function () {
			var total = $( '.cc-domain-checkbox' ).length;
			var checked = $( '.cc-domain-checkbox:checked' ).length;
			$( '#cc-select-all-domains' ).prop( 'checked', total > 0 && total === checked );
			updateSelectedCount();
		} );

		// --- Zone Manager tab --------------------------------------------------

		$( '#cc-refresh-zones' ).on( 'click', function () {
			var $btn    = $( this );
			var $result = $( '#cc-refresh-result' );

			$btn.prop( 'disabled', true ).text( CoreCloudflare.i18n.working );

			$.post( CoreCloudflare.ajaxUrl, {
				action: 'core_cloudflare_refresh_zones',
				nonce:  CoreCloudflare.nonce,
			} )
				.done( function ( response ) {
					if ( response.success ) {
						renderMessage(
							$result,
							CoreCloudflare.i18n.zoneMapped
								.replace( '%1$d', response.data.mapped )
								.replace( '%2$d', response.data.total_zones ),
							false
						);
						if ( response.data.error ) {
							renderMessage( $result, $result.find( 'p' ).text() + ' — ' + response.data.error, false );
						}
						window.setTimeout( function () { window.location.reload(); }, 1200 );
					} else {
						renderMessage( $result, response.data.error || CoreCloudflare.i18n.errorRefreshFailed, true );
					}
				} )
				.fail( function ( xhr ) {
					var payload = xhr && xhr.responseJSON ? xhr.responseJSON : null;
					var data = payload && payload.data ? payload.data : null;
					var message = data && data.message ? data.message : getAjaxErrorMessage( xhr, CoreCloudflare.i18n.errorRequestFailed );

					// Some hosts/plugins incorrectly convert wp_send_json_error() into
					// a non-2xx response. Treat a JSON validation payload as a handled
					// form-validation result, never as a generic AJAX/network failure.
					if ( data && ( data.field_errors || data.errors || data.message ) ) {
						renderMessage( $result, message, true );
						renderSettingsFieldErrors( data.field_errors || {} );
						if ( data.errors && data.errors.length ) {
							renderErrorList( $result, data.errors );
						}
						return;
					}

					renderMessage( $result, message, true );
				} )
				.always( function () {
					$btn.prop( 'disabled', false ).text( CoreCloudflare.i18n.btnRefreshZones );
				} );
		} );

		// --- Configuration tab -------------------------------------------------

		// Domain rows and credential UI: show only the row matching the selected environment.
		if ( $( '#cc-environment' ).length ) {
			toggleDomainRows();
			updateCredentialUi( $( '#cc-environment' ).val() );
			$( '#cc-environment' ).on( 'change', function () {
				var env = $( this ).val() || 'staging';

				// Validation belongs to the environment that was submitted.
				// Never carry Staging/Live errors into the other environment when
				// the administrator switches the selector.
				clearSettingsFieldErrors();
				$( '#cc-settings-result' ).empty();
				toggleDomainRows();
				updateCredentialUi( env );
				syncCredentialsForEnvironment( env );

				// Keep the credential delete button label tied to the selected
				// environment as well.
				$( '#cc-delete-credentials' ).text( env === 'production' ? 'Delete Live Credentials' : 'Delete Staging Credentials' );
			} );
		}

		if ( $( '#cc-account-id' ).length ) {
			syncCredentialsForEnvironment( $( '#cc-environment' ).val() );
		}

		$( '#cc-delete-credentials' ).on( 'click', function () {
			var env = $( '#cc-environment' ).val() || 'staging';
			var $result = $( '#cc-settings-result' );

			if ( ! window.confirm( 'Delete the selected environment Cloudflare credentials?' ) ) {
				return;
			}

			$.post( CoreCloudflare.ajaxUrl, {
				action: 'core_cloudflare_delete_credentials',
				nonce:  CoreCloudflare.nonce,
				environment: env,
			} )
				.done( function ( response ) {
					if ( response.success ) {
						$( '#cc-token-masked' ).text( response.data.masked_token || '(not configured)' );
						$( '#cc-account-id-masked' ).text( response.data.masked_account_id || '(not configured)' );
						updateCredentialFields( false, false );
						renderMessage( $result, response.data.message, false );
					} else {
						var errorMessage = response.data.message || CoreCloudflare.i18n.errorSaveFailed;
						if ( response.data.errors && response.data.errors.length ) {
							errorMessage += '<br><ul><li>' + response.data.errors.join( '</li><li>' ) + '</li></ul>';
						}
						renderMessage( $result, errorMessage, true );
					}
				} )
				.fail( function ( xhr ) {
					var message = getAjaxErrorMessage( xhr, CoreCloudflare.i18n.errorRequestFailed );
					if ( xhr && xhr.responseJSON && xhr.responseJSON.data ) {
						message = getAjaxErrorMessage( xhr, xhr.responseJSON.data.message || message );
						if ( xhr.responseJSON.data.errors && xhr.responseJSON.data.errors.length ) {
							renderMessage( $result, message, true );
							renderErrorList( $result, xhr.responseJSON.data.errors );
							return;
						}
					}
					renderMessage( $result, message, true );
				} );
		} );

		// Masked-token display: update inline after a successful save.
		$( '#cc-settings-form' ).on( 'submit', function ( e ) {
			e.preventDefault();

			var $form   = $( this );
			var $btn    = $( '#cc-save-settings' );
			var $result = $( '#cc-settings-result' );

			clearSettingsFieldErrors();
			var data = $form.serializeArray();
			data.push( { name: 'action', value: 'core_cloudflare_save_settings' } );
			data.push( { name: 'nonce',  value: CoreCloudflare.nonce } );

			$btn.prop( 'disabled', true ).text( CoreCloudflare.i18n.working );

			$.post( CoreCloudflare.ajaxUrl, $.param( data ) )
				.done( function ( response ) {
					if ( response.success ) {
						renderMessage( $result, response.data.message, false );

						if ( response.data.warnings && response.data.warnings.length ) {
							renderWarningList( $result, response.data.warnings );
						}

						// Clear secret inputs and refresh the selected environment's
						// credential display using the values returned by PHP. Passing
						// the masked values here is important: otherwise the helper
						// falls back to '(not configured)' immediately after a save.
						$( '#cc-api-token' ).val( '' );
						$( '#cc-account-id' ).val( '' );
						updateCredentialFields(
							!! response.data.has_token,
							!! response.data.has_account_id,
							response.data.masked_token || '(not configured)',
							response.data.masked_account_id || '(not configured)'
						);

						// Keep the in-page environment cache synchronized so switching
						// Staging/Live after saving does not restore stale credentials.
						var credentials = {};
						try { credentials = JSON.parse( $form.attr( 'data-credentials' ) || '{}' ); } catch ( e ) {}
						var savedEnv = $( '#cc-environment' ).val() || 'staging';
						credentials[ savedEnv ] = credentials[ savedEnv ] || {};
						credentials[ savedEnv ].has_token = !! response.data.has_token;
						credentials[ savedEnv ].has_account_id = !! response.data.has_account_id;
						credentials[ savedEnv ].masked_token = response.data.masked_token || '(not configured)';
						credentials[ savedEnv ].masked_account = response.data.masked_account_id || '(not configured)';
						$form.attr( 'data-credentials', JSON.stringify( credentials ) );
					} else {
						// Validation failures are returned with HTTP 200 so they stay in
						// this handler. Show the exact environment-specific validation
						// message instead of a generic AJAX failure.
						renderMessage( $result, response.data.message || CoreCloudflare.i18n.errorSaveFailed, true );
						renderSettingsFieldErrors( response.data.field_errors || {} );
						if ( response.data.errors && response.data.errors.length > 1 ) {
							renderErrorList( $result, response.data.errors.slice( 1 ) );
						}
					}
				} )
				.fail( function ( xhr ) {
					var data = xhr && xhr.responseJSON ? xhr.responseJSON.data : null;
					var message = getAjaxErrorMessage( xhr, CoreCloudflare.i18n.errorRequestFailed );

					// Validation failures intentionally use HTTP 422. jQuery routes
					// those responses to fail(), so render the server's field-level
					// validation details here instead of showing a generic AJAX error.
					if ( data ) {
						if ( data.message ) {
							message = data.message;
						}
						renderMessage( $result, message, true );

						if ( data.field_errors ) {
							renderSettingsFieldErrors( data.field_errors );
						}

						if ( data.errors && data.errors.length ) {
							renderErrorList( $result, data.errors );
						}
						return;
					}

					renderMessage( $result, message, true );
				} )
				.always( function () {
					$btn.prop( 'disabled', false ).text( CoreCloudflare.i18n.btnSaveSettings );
				} );
		} );

// --- Logs tab ----------------------------------------------------------

		$( '#cc-clear-logs' ).on( 'click', function () {
			if ( ! window.confirm( CoreCloudflare.i18n.confirmClearLogs ) ) {
				return;
			}
			$.post( CoreCloudflare.ajaxUrl, {
				action: 'core_cloudflare_clear_logs',
				nonce:  CoreCloudflare.nonce,
			} ).done( function () {
				window.location.reload();
			} );
		} );
	} );


	
} )( jQuery );
