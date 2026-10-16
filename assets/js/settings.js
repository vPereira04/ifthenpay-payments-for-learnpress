/* global iftpLpSettings */
// LearnPress › Settings › Payments › ifthenpay: the Connect / Disconnect dialogs, the Gateway Key switch and the methods table.
// No styles anywhere: state lives in `hidden`, `disabled` and the dialogs' own open state.
( function () {
	'use strict';

	if ( 'undefined' === typeof iftpLpSettings ) {
		return;
	}

	const cfg = iftpLpSettings;
	const i18n = cfg.i18n;

	function post( action, data ) {
		const body = new URLSearchParams();

		body.set( 'action', action );
		body.set( 'nonce', cfg.nonce );

		Object.keys( data || {} ).forEach( function ( key ) {
			if ( Array.isArray( data[ key ] ) ) {
				data[ key ].forEach( function ( value ) {
					body.append( key + '[]', value );
				} );
			} else {
				body.set( key, data[ key ] );
			}
		} );

		return fetch( cfg.ajaxUrl, { method: 'POST', body, credentials: 'same-origin' } ).then( function ( response ) {
			return response.json();
		} );
	}

	function errorMessage( response ) {
		return ( response && response.data && response.data.message ) || i18n.error;
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		// The dialogs are printed in the footer, outside LearnPress's form, so I look them up on the whole page.
		if ( ! document.getElementById( 'iftp-lp-settings' ) ) {
			return;
		}

		bindDialogs( document );
		bindConnect( document );
		bindDisconnect( document );
		bindConfiguration( document );
	} );

	// Every Cancel button closes its own dialog; Escape comes with <dialog> for free.
	function bindDialogs( wrap ) {
		wrap.addEventListener( 'click', function ( event ) {
			const closeBtn = event.target.closest( '[data-iftp-dialog-close]' );
			const dialog = closeBtn ? closeBtn.closest( 'dialog' ) : null;

			if ( dialog ) {
				dialog.close();
			}
		} );
	}

	function bindConnect( wrap ) {
		const opener = wrap.querySelector( '[data-iftp-connect-open]' );
		const dialog = wrap.querySelector( '[data-iftp-connect-dialog]' );

		if ( ! opener || ! dialog ) {
			return;
		}

		const form = dialog.querySelector( '[data-iftp-connect-form]' );
		const input = dialog.querySelector( '[data-iftp-backoffice-key]' );
		const errorEl = dialog.querySelector( '[data-iftp-connect-error]' );
		const submitBtn = dialog.querySelector( '[data-iftp-connect-submit]' );

		function fail( message ) {
			errorEl.textContent = message;
			input.setAttribute( 'aria-invalid', 'true' );
			input.focus();
			submitBtn.disabled = false;
			submitBtn.textContent = i18n.connect;
		}

		opener.addEventListener( 'click', function () {
			errorEl.textContent = '';
			input.removeAttribute( 'aria-invalid' );
			dialog.showModal();
			input.focus();
		} );

		// Focus goes back to Connect however the dialog closed.
		dialog.addEventListener( 'close', function () {
			opener.focus();
		} );

		form.addEventListener( 'submit', function ( event ) {
			const key = String( input.value || '' ).trim();

			event.preventDefault();

			// Same format the server checks, so a typo is caught without a round-trip.
			if ( ! /^\d{4}-\d{4}-\d{4}-\d{4}$/.test( key ) ) {
				fail( i18n.invalidKey );
				return;
			}

			errorEl.textContent = '';
			input.removeAttribute( 'aria-invalid' );
			submitBtn.disabled = true;
			submitBtn.textContent = i18n.connecting;

			post( 'iftp_lp_connect', { backoffice_key: key } )
				.then( function ( response ) {
					if ( ! response || ! response.success ) {
						fail( errorMessage( response ) );
						return;
					}

					// The button turns into Disconnect and the configuration appears, both rendered server-side.
					window.location.reload();
				} )
				.catch( function () {
					fail( i18n.error );
				} );
		} );
	}

	function bindDisconnect( wrap ) {
		const opener = wrap.querySelector( '[data-iftp-disconnect-open]' );
		const dialog = wrap.querySelector( '[data-iftp-disconnect-dialog]' );

		if ( ! opener || ! dialog ) {
			return;
		}

		const message = wrap.querySelector( '[data-iftp-connection-message]' );
		const confirmBtn = dialog.querySelector( '[data-iftp-disconnect-confirm]' );

		opener.addEventListener( 'click', function () {
			dialog.showModal();
		} );

		dialog.addEventListener( 'close', function () {
			if ( ! confirmBtn.disabled ) {
				opener.focus();
			}
		} );

		confirmBtn.addEventListener( 'click', function () {
			confirmBtn.disabled = true;

			post( 'iftp_lp_disconnect', {} )
				.then( function ( response ) {
					if ( ! response || ! response.success ) {
						return Promise.reject( errorMessage( response ) );
					}

					window.location.reload();
				} )
				.catch( function ( error ) {
					confirmBtn.disabled = false;
					dialog.close();
					message.textContent = 'string' === typeof error ? error : i18n.error;
				} );
		} );
	}

	// The configuration saves with LearnPress's own Save; only the Gateway Key switch and activation requests go over AJAX.
	function bindConfiguration( wrap ) {
		const config = wrap.querySelector( '[data-iftp-configuration]' );

		if ( ! config ) {
			return;
		}

		const methods = config.querySelector( '[data-iftp-methods]' );
		const gatewayKey = config.querySelector( '[data-iftp-gateway-key]' );
		const status = config.querySelector( '[data-iftp-status]' );
		let statusTimer = null;

		// One live region under the table. Errors stay; a success clears after a while.
		function showStatus( text, isError ) {
			clearTimeout( statusTimer );
			status.textContent = text;
			status.classList.toggle( 'iftp-lp-methods-panel__status--error', !! isError );

			if ( ! isError && text ) {
				statusTimer = setTimeout( function () {
					status.textContent = '';
				}, 4000 );
			}
		}

		gatewayKey.addEventListener( 'change', function () {
			methods.setAttribute( 'aria-busy', 'true' );
			gatewayKey.disabled = true;
			showStatus( i18n.loading, false );

			post( 'iftp_lp_select_gateway_key', { gateway_key: gatewayKey.value } )
				.then( function ( response ) {
					if ( ! response || ! response.success ) {
						showStatus( errorMessage( response ), true );
						return;
					}

					methods.innerHTML = response.data.tableHtml;
					showStatus( '', false );
				} )
				.catch( function () {
					showStatus( i18n.error, true );
				} )
				.then( function () {
					methods.removeAttribute( 'aria-busy' );
					gatewayKey.disabled = false;
				} );
		} );

		// The star only exists while its row is enabled; disabling the default method clears the default.
		methods.addEventListener( 'change', function ( event ) {
			const target = event.target;

			if ( target.matches( '[data-iftp-method-enabled]' ) ) {
				syncRow( target.closest( '[data-iftp-method-row]' ), target.checked );
				syncStars( methods );
			} else if ( target.matches( '[data-iftp-method-default]' ) ) {
				syncStars( methods );
				wink( target.closest( 'td' ).querySelector( '[data-iftp-star]' ) );
			}
		} );

		methods.addEventListener( 'click', function ( event ) {
			const button = event.target.closest( '[data-iftp-request-activation]' );

			if ( button ) {
				requestActivation( button, showStatus );
			}
		} );
	}

	function syncRow( row, enabled ) {
		const radio = row.querySelector( '[data-iftp-method-default]' );
		const star = row.querySelector( '[data-iftp-star]' );
		const badge = row.querySelector( '[data-iftp-method-status]' );

		radio.disabled = ! enabled;
		star.hidden = ! enabled;
		star.classList.toggle( 'iftp-lp-star--hidden', ! enabled );

		if ( ! enabled ) {
			radio.checked = false;
		}

		// Only a preview; it's stored with the rest of the section on LearnPress's Save.
		if ( badge ) {
			badge.classList.toggle( 'iftp-lp-method-status--enabled', enabled );
			badge.classList.toggle( 'iftp-lp-method-status--activated', ! enabled );
			badge.textContent = badge.getAttribute( enabled ? 'data-label-enabled' : 'data-label-activated' );
		}
	}

	// Filled star for the checked radio, outline for the rest, so the default reads even without styles.
	function syncStars( scope ) {
		scope.querySelectorAll( '[data-iftp-method-default]' ).forEach( function ( radio ) {
			const star = radio.closest( 'td' ).querySelector( '[data-iftp-star]' );
			const glyph = star.querySelector( '[data-iftp-star-glyph]' );

			star.classList.toggle( 'iftp-lp-star--checked', radio.checked );
			star.title = radio.checked ? i18n.starOn : i18n.starOff;
			glyph.textContent = radio.checked ? '★' : '☆';
		} );
	}

	// Remove, reflow, re-add: a hook for the future wink animation that replays even on the same star.
	function wink( star ) {
		if ( ! star ) {
			return;
		}

		star.classList.remove( 'iftp-lp-star--wink' );
		void star.offsetWidth;
		star.classList.add( 'iftp-lp-star--wink' );
	}

	// Each method's 24h cooldown is enforced server-side; the disabled "Requested" button only mirrors it.
	function requestActivation( button, showStatus ) {
		const original = button.textContent;

		button.disabled = true;
		button.textContent = i18n.requesting;

		post( 'iftp_lp_request_activation', { entity: button.getAttribute( 'data-iftp-request-activation' ) } )
			.then( function ( response ) {
				if ( ! response || ! response.success ) {
					return Promise.reject( errorMessage( response ) );
				}

				button.textContent = i18n.requested;
				showStatus( i18n.requestSent, false );
				window.alert( i18n.requestSent );
			} )
			.catch( function ( error ) {
				const text = 'string' === typeof error ? error : i18n.error;

				button.disabled = false;
				button.textContent = original;
				showStatus( text, true );
				window.alert( text );
			} );
	}
} )();
