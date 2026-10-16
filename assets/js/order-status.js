/* global iftpLpOrderStatus */
// LearnPress's order-received page: watches an ifthenpay order and reloads as soon as its payment status changes,
// so "waiting" turns into the completed order without the student refreshing.
// Fast first (a card payment confirms within seconds), then slower for Multibanco/Payshop, which can take a while.
( function () {
	'use strict';

	if ( 'undefined' === typeof iftpLpOrderStatus ) {
		return;
	}

	const cfg = iftpLpOrderStatus;
	const fastTries = 10;
	const fastDelay = 3000;
	const slowDelay = 10000;
	const budget = 25 * 60 * 1000;
	const startedAt = Date.now();
	let tries = 0;
	let timer = 0;
	let inFlight = false;
	let stopped = false;

	function schedule() {
		window.clearTimeout( timer );

		if ( stopped || Date.now() - startedAt > budget ) {
			return;
		}

		timer = window.setTimeout( check, tries < fastTries ? fastDelay : slowDelay );
	}

	// Paid (or the order completed some other way) ends the watch; any other change reloads, since the page's
	// message and actions depend on it, and the reloaded page keeps watching.
	function check() {
		if ( stopped || inFlight ) {
			return;
		}

		const body = new URLSearchParams();

		inFlight = true;
		tries++;
		body.set( 'action', 'iftp_lp_order_status' );
		body.set( 'nonce', cfg.nonce );
		body.set( 'order_id', String( cfg.orderId ) );
		body.set( 'key', cfg.key );

		fetch( cfg.ajaxUrl, { method: 'POST', body, credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( response ) {
				const data = response && response.success ? response.data : null;

				if ( data && ( data.completed || data.status !== cfg.status ) ) {
					stopped = true;
					window.location.reload();
				}
			} )
			.catch( function () {
				// A dropped request just waits for the next try.
			} )
			.then( function () {
				inFlight = false;
				schedule();
			} );
	}

	// Browsers throttle timers in a background tab; the student coming back from their banking app checks right away.
	document.addEventListener( 'visibilitychange', function () {
		if ( 'visible' === document.visibilityState && ! stopped ) {
			window.clearTimeout( timer );
			check();
		}
	} );

	// One check straight away: most returns already have their answer.
	check();
} )();
