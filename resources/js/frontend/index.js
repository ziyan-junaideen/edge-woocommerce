/**
 * Edge Payments — block checkout integration.
 *
 * The server creates an unconfirmed payment demand, Edge's hosted iframe
 * collects and verifies the card against it, and only the opaque demand id
 * crosses back into WooCommerce. No card data touches this bundle.
 */

import { __ } from '@wordpress/i18n';
import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { decodeEntities } from '@wordpress/html-entities';
import { getSetting } from '@woocommerce/settings';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { CART_STORE_KEY } from '@woocommerce/block-data';
import apiFetch from '@wordpress/api-fetch';

const settings = getSetting( 'edge_data', {} );

const label = decodeEntities( settings.title ) || __( 'Credit Card', 'edge-gateway' );

/**
 * How long to wait for a verification outcome before giving up.
 *
 * The SDK's own default is 180s. This is shorter so a shopper is not left
 * staring at a spinner, but still generous enough for a 3DS challenge.
 */
const VERIFY_TIMEOUT_MS = 120000;

/**
 * Pause to wait for before preparing, so a burst of address updates results in
 * one demand rather than one per field.
 */
const PREPARE_DEBOUNCE_MS = 700;

/**
 * How the block waits for the card network's answer.
 *
 * Confirming a demand only means Edge accepted the payment for processing. The
 * acquirer's answer lands afterwards — a second or three live, up to about 25s
 * in sandbox — so the outcome has to be waited for rather than assumed.
 *
 * The first twenty seconds cover almost every payment, so poll briskly there
 * and ease off afterwards rather than hammering the site for two minutes.
 */
const STATUS_POLL_MS = 2000;
const STATUS_SLOW_POLL_MS = 4000;
const STATUS_SLOW_AFTER_MS = 20000;

/**
 * How long to wait for a terminal outcome before sending the shopper on.
 *
 * Long enough that a slow acquirer is not mistaken for a stuck one, and short
 * enough that nobody is held on a locked form indefinitely.
 */
const OUTCOME_TIMEOUT_MS = 120000;

/**
 * The one Edge client on the page.
 *
 * edge.js registers global `window` message handlers in its constructor and
 * exposes no `destroy()`, so every additional instance leaks a handler that
 * outlives the component. Keeping exactly one, reused across remounts, is the
 * mitigation. Reported upstream.
 */
let sharedClient = null;

const getEdgeClient = ( publishableKey, host ) => {
	if ( sharedClient ) {
		return sharedClient;
	}

	if ( typeof window.Edge !== 'function' ) {
		return null;
	}

	sharedClient = new window.Edge( publishableKey, {
		formFactor: 'inputs',
		host,
	} );

	return sharedClient;
};

/**
 * Ask the server for a payment demand to mount against.
 *
 * No cart details are sent. The server reads the cart, total, currency and mode
 * from the session; the browser is not a source of truth for any of them.
 */
const prepareIntent = () =>
	apiFetch( {
		path: '/edge/v1/checkout-intent',
		method: 'POST',
	} );

/**
 * Ask the server what became of an order's payment.
 *
 * Only the order id goes out: the browser names the order it is waiting on and
 * the server decides everything else, including whether this session is
 * entitled to an answer at all.
 */
const readStatus = ( orderId ) =>
	apiFetch( {
		path: '/edge/v1/checkout-status',
		method: 'POST',
		data: { order_id: orderId },
	} );

/**
 * What to tell the shopper about a payment the server says failed.
 *
 * The server's own wording is preferred because it is the only thing that knows
 * why, but it is used only when it is actually a string — a malformed body must
 * not turn into an empty notice.
 */
const declineMessage = ( response ) =>
	'string' === typeof response?.message && response.message
		? response.message
		: __(
				'Your payment was declined. Please check your card details or try another card.',
				'edge-gateway'
		  );

const EdgePaymentForm = ( { eventRegistration, emitResponse } ) => {
	const { onPaymentSetup, onCheckoutSuccess } = eventRegistration;

	// Stable, unique container id: edge.js looks the element up by id, so two
	// mounted instances must not collide.
	const containerId = useRef(
		`edge-payment-form-${ Math.random().toString( 36 ).slice( 2, 10 ) }`
	).current;

	const [ demandId, setDemandId ] = useState( null );
	const [ failure, setFailure ] = useState( null );

	// Where the post-order wait has got to: null, 'waiting' or 'slow'.
	//
	// Deliberately not `failure`, which blocks payment setup outright. A
	// declined payment has to stay retryable, so waiting must never take the
	// shopper's only route back through the form away from them.
	const [ waitStage, setWaitStage ] = useState( null );

	// Resume mode: the order whose payment this session already has in flight,
	// as named by the server when it refused to prepare another demand.
	const [ resumeOrderId, setResumeOrderId ] = useState( null );

	// How a resumed payment's decline is reported. Separate from `failure`
	// because it must survive into the next attempt without blocking it: there
	// is no Blocks notice outside the checkout lifecycle to carry it instead.
	const [ resumeError, setResumeError ] = useState( null );

	// Bumped to ask for a fresh demand when nothing the prepare effect already
	// watches has changed.
	const [ retryToken, setRetryToken ] = useState( 0 );

	// Which demand, if any, currently holds a verified card. Held in a ref
	// because the payment-setup callback reads it outside React's render cycle.
	const verifiedFor = useRef( null );
	const pending = useRef( null );
	const mountedDemand = useRef( null );

	// Tears down whatever wait is in flight, if any. See the unmount effect.
	const abandonWait = useRef( null );

	// Everything the server fingerprints a demand against. Re-preparing when any
	// of it changes is what keeps the mounted demand from going stale: Edge
	// silently returns the original resource for a reused idempotency key, so a
	// changed cart or address needs a genuinely new demand.
	//
	// These come from the cart store rather than the form inputs, so they update
	// when Blocks has synced them to the server - not on every keystroke.
	const paymentFacts = useSelect( ( select ) => {
		const store = select( CART_STORE_KEY );
		const totals = store.getCartTotals();
		const customer = store.getCustomerData();

		return JSON.stringify( {
			total: totals?.total_price,
			currency: totals?.currency_code,
			billing: customer?.billingAddress,
			shipping: customer?.shippingAddress,
		} );
	}, [] );

	const settle = useCallback( ( outcome ) => {
		if ( pending.current ) {
			const { resolve, timer } = pending.current;
			pending.current = null;
			window.clearTimeout( timer );
			resolve( outcome );
		}
	}, [] );

	// --- Obtain a demand -----------------------------------------------------
	useEffect( () => {
		// Nothing may be prepared while a payment is being resumed: that is the
		// whole point of resume mode, and asking again would only be refused.
		if ( resumeOrderId ) {
			return undefined;
		}

		let cancelled = false;

		// Address fields settle in bursts as Blocks syncs them, so wait for a
		// pause rather than preparing against every intermediate state.
		const timer = window.setTimeout( () => {
			setFailure( null );
			verifiedFor.current = null;

			prepareIntent()
				.then( ( response ) => {
					if ( ! cancelled ) {
						setDemandId( response.demandId );
					}
				} )
				.catch( ( error ) => {
					if ( cancelled ) {
						return;
					}

					setDemandId( null );

					// This session already has an Edge payment settling —
					// typically the shopper reloaded the checkout, which still
					// holds a full cart, while their order was in flight.
					// Preparing a demand here would give them a second order to
					// pay for the same goods, so wait the first one out instead
					// of starting anything.
					const inFlightOrder = error?.data?.orderId;

					if (
						'edge_payment_in_flight' === error?.code &&
						'number' === typeof inFlightOrder &&
						inFlightOrder
					) {
						setResumeOrderId( inFlightOrder );

						return;
					}

					setFailure(
						error?.message ||
							__(
								'We could not start your card payment. Please try again.',
								'edge-gateway'
							)
					);
				} );
		}, PREPARE_DEBOUNCE_MS );

		return () => {
			cancelled = true;
			window.clearTimeout( timer );
		};
	}, [ paymentFacts, retryToken, resumeOrderId ] );

	// --- Mount the hosted form and listen ------------------------------------
	useEffect( () => {
		if ( ! demandId ) {
			return undefined;
		}

		const client = getEdgeClient( settings.publishableKey, settings.iframeHost );

		if ( ! client ) {
			setFailure(
				__(
					'The secure payment form could not be loaded. Please refresh and try again.',
					'edge-gateway'
				)
			);

			return undefined;
		}

		// Listeners go on before anything can emit, so no outcome is missed.
		const unsubscribers = [
			client.on( 'payment_method_verified', () => {
				verifiedFor.current = demandId;
				settle( { ok: true } );
			} ),

			client.on( 'payment_method_error', () => {
				verifiedFor.current = null;
				settle( {
					ok: false,
					message: __(
						'Your bank could not verify that card. Please check the details or try another card.',
						'edge-gateway'
					),
				} );
			} ),

			client.on( 'payment_method_failed', () => {
				verifiedFor.current = null;
				settle( {
					ok: false,
					message: __(
						'That card could not be authenticated. Please try another card.',
						'edge-gateway'
					),
				} );
			} ),

			// Fires whenever the fields change, including after a successful
			// verification — an edited card invalidates the previous result.
			client.on( 'payment_method_changed', () => {
				verifiedFor.current = null;
			} ),
		];

		try {
			const iframe = client.mountPaymentForm( containerId, demandId );

			// The SDK sizes the iframe from its own contentRect, but that content
			// is constrained by the iframe's current width — which starts at the
			// browser default of 300px. Left alone it measures 300, reports 300
			// and never grows. An inline style beats the width attribute the SDK
			// keeps setting, so the form fills the container and the 3DS
			// challenge is sized from a realistic width rather than the smallest
			// possible one.
			if ( iframe ) {
				iframe.style.width = '100%';
				iframe.style.border = '0';
				iframe.style.display = 'block';
			}

			mountedDemand.current = demandId;
		} catch ( error ) {
			setFailure(
				__(
					'The secure payment form could not be displayed. Please refresh and try again.',
					'edge-gateway'
				)
			);
		}

		return () => {
			unsubscribers.forEach( ( off ) => {
				if ( typeof off === 'function' ) {
					off();
				}
			} );

			settle( {
				ok: false,
				message: __( 'Payment was interrupted. Please try again.', 'edge-gateway' ),
			} );

			mountedDemand.current = null;

			// The shared client has no teardown, so remove the iframe directly.
			const container = document.getElementById( containerId );

			if ( container ) {
				container.innerHTML = '';
			}
		};
	}, [ demandId, settle, containerId ] );

	// --- Verify on "Place order" ---------------------------------------------
	useEffect( () => {
		const unsubscribe = onPaymentSetup( async () => {
			const fail = ( message ) => ( {
				type: emitResponse.responseTypes.ERROR,
				message,
			} );

			if ( failure ) {
				return fail( failure );
			}

			// Checked before the readiness branch below, which would otherwise
			// answer "the form is not ready" — true, but not the reason.
			if ( resumeOrderId ) {
				return fail(
					__(
						'Your previous payment is still being processed. Please wait a moment.',
						'edge-gateway'
					)
				);
			}

			if ( ! demandId || mountedDemand.current !== demandId ) {
				return fail(
					__(
						'The payment form is not ready yet. Please wait a moment and try again.',
						'edge-gateway'
					)
				);
			}

			// A second submit must never start a parallel verification; it joins
			// the one already running.
			let waiting = pending.current ? pending.current.outcome : null;

			if ( ! waiting ) {
				const client = getEdgeClient( settings.publishableKey, settings.iframeHost );

				if ( ! client ) {
					return fail(
						__( 'The secure payment form is unavailable.', 'edge-gateway' )
					);
				}

				const outcome = new Promise( ( resolve ) => {
					const timer = window.setTimeout( () => {
						pending.current = null;
						resolve( {
							ok: false,
							message: __(
								'Verifying your card took too long. Please try again.',
								'edge-gateway'
							),
						} );
					}, VERIFY_TIMEOUT_MS );

					pending.current = { resolve, timer };
				} );

				// Published before the call that can settle it. A throw from
				// verifyPaymentMethod() runs settle(), which clears
				// pending.current, and assigning onto it afterwards would be a
				// TypeError instead of the decline the catch meant to produce.
				pending.current.outcome = outcome;
				waiting = outcome;

				// The returned promise is deliberately ignored: it never rejects,
				// and it resolves on `payment_method_changed`, which fires as soon
				// as the fields are merely filled in. The event handlers above are
				// the only trustworthy signal.
				try {
					client.verifyPaymentMethod();
				} catch ( error ) {
					settle( {
						ok: false,
						message: __( 'Your card could not be verified.', 'edge-gateway' ),
					} );
				}
			}

			// Held in a local for the same reason: by the time this is awaited,
			// pending.current may already have been cleared by settle() or by the
			// verification timeout, and the promise is what is being waited on.
			const result = await waiting;

			if ( ! result.ok ) {
				return fail( result.message );
			}

			// Guard against a card edited between verification and submission.
			if ( verifiedFor.current !== demandId ) {
				return fail(
					__(
						'Your card details changed. Please submit again to verify them.',
						'edge-gateway'
					)
				);
			}

			return {
				type: emitResponse.responseTypes.SUCCESS,
				meta: {
					paymentMethodData: {
						edge_demand_id: demandId,
					},
				},
			};
		} );

		return () => unsubscribe();
	}, [
		onPaymentSetup,
		emitResponse.responseTypes.ERROR,
		emitResponse.responseTypes.SUCCESS,
		demandId,
		failure,
		resumeOrderId,
		settle,
	] );

	// --- Wait for an order's payment to settle -------------------------------
	//
	// The one piece of waiting machinery, shared by the two situations that
	// need it: the checkout lifecycle holding open after "Place order", and a
	// reloaded page resuming a payment that was already in flight. It knows
	// nothing about either — it polls, and reports.
	//
	// `report( status, response )` is called at most once with 'succeeded',
	// 'failed', 'timeout' or 'abandoned'. Returning `false` from it declines
	// the answer and the wait carries on, which is how a caller that cannot act
	// on a success says so. Returns a cancel function.
	const watchOutcome = useCallback( ( orderId, hintDemandId, report ) => {
		const startedAt = Date.now();
		const unsubscribers = [];

		let settled = false;
		let inFlight = false;
		let pollAgain = false;
		let pollTimer = null;
		let slowTimer = null;
		let giveUpTimer = null;

		function stop() {
			settled = true;

			window.clearTimeout( pollTimer );
			window.clearTimeout( slowTimer );
			window.clearTimeout( giveUpTimer );

			unsubscribers.forEach( ( off ) => {
				if ( typeof off === 'function' ) {
					off();
				}
			} );

			setWaitStage( null );
		}

		function schedule() {
			if ( settled ) {
				return;
			}

			window.clearTimeout( pollTimer );

			pollTimer = window.setTimeout(
				poll,
				Date.now() - startedAt >= STATUS_SLOW_AFTER_MS
					? STATUS_SLOW_POLL_MS
					: STATUS_POLL_MS
			);
		}

		// Either the timer or an SDK hint got here first; both land in the
		// same place so there is only ever one request outstanding.
		function again() {
			if ( pollAgain ) {
				pollAgain = false;
				poll();

				return;
			}

			schedule();
		}

		function deliver( status, response ) {
			if ( settled ) {
				return;
			}

			if ( false === report( status, response ) ) {
				again();

				return;
			}

			stop();
		}

		function poll() {
			if ( settled ) {
				return;
			}

			if ( inFlight ) {
				pollAgain = true;

				return;
			}

			window.clearTimeout( pollTimer );
			inFlight = true;

			readStatus( orderId )
				.then( ( response ) => {
					inFlight = false;

					if ( settled ) {
						return;
					}

					if (
						'succeeded' === response?.status ||
						'failed' === response?.status
					) {
						deliver( response.status, response );

						return;
					}

					again();
				} )
				.catch( () => {
					// A transport failure or an unreadable body says nothing
					// about the payment, so it is not an outcome — keep
					// waiting, and never repeat a server's own words to the
					// shopper.
					inFlight = false;

					if ( ! settled ) {
						again();
					}
				} );
		}

		// No demand mounted means no iframe to hear from, which is the resume
		// case: the poll is the only source of news there.
		if ( hintDemandId ) {
			const client = getEdgeClient(
				settings.publishableKey,
				settings.iframeHost
			);

			if ( client ) {
				// The iframe hears the acquirer before WordPress does, but a
				// message from it is not evidence that an order moved. The hint
				// only brings the next poll forward; the server's answer is
				// still the only thing acted on.
				const hint = ( event ) => {
					if ( event?.detail?.paymentId === hintDemandId ) {
						poll();
					}
				};

				unsubscribers.push(
					client.on( 'payment_approved', hint ),
					client.on( 'payment_failed', hint )
				);
			}
		}

		// A payment is underway again, so last attempt's decline has been
		// answered and should stop being shown.
		setResumeError( null );
		setWaitStage( 'waiting' );

		slowTimer = window.setTimeout( () => {
			setWaitStage( 'slow' );
		}, STATUS_SLOW_AFTER_MS );

		giveUpTimer = window.setTimeout( () => {
			if ( ! settled ) {
				stop();
				report( 'timeout', null );
			}
		}, OUTCOME_TIMEOUT_MS );

		schedule();

		return () => {
			if ( ! settled ) {
				stop();
				report( 'abandoned', null );
			}
		};
	}, [] );

	// --- Hold the checkout open until the payment has actually settled -------
	//
	// The demand id is passed in rather than read from the ref, so a remount
	// midway cannot point the wait at another payment.
	const awaitOutcome = useCallback(
		( orderId, outcomeDemandId ) =>
			new Promise( ( resolve ) => {
				// The thank-you page with the order left on-hold is what this
				// gateway did before it waited at all, so it is the safe way
				// out of a wait that has gone on too long — or of a component
				// being unmounted underneath one. A shopper is never left on a
				// locked form, and a decline nobody has actually reported is
				// never announced as one.
				const giveUp = { type: emitResponse.responseTypes.SUCCESS };

				abandonWait.current = watchOutcome(
					orderId,
					outcomeDemandId,
					( status, response ) => {
						abandonWait.current = null;

						if ( 'succeeded' === status ) {
							const outcome = {
								type: emitResponse.responseTypes.SUCCESS,
							};

							// Left off rather than sent empty, so the store
							// falls back to the redirect it already holds.
							if (
								'string' === typeof response.redirectUrl &&
								response.redirectUrl
							) {
								outcome.redirectUrl = response.redirectUrl;
							}

							resolve( outcome );

							return true;
						}

						if ( 'failed' === status ) {
							// The card is what needs fixing and the iframe is
							// still mounted holding it — it is never torn down,
							// because the SDK cannot remount without stacking a
							// second iframe, and Edge accepts a fresh card on a
							// failed demand. Dropping the verification is what
							// makes the next "Place order" re-verify against it.
							verifiedFor.current = null;

							resolve( {
								type: emitResponse.responseTypes.ERROR,
								message: declineMessage( response ),
								messageContext:
									emitResponse.noticeContexts.PAYMENTS,
							} );

							return true;
						}

						resolve( giveUp );

						return true;
					}
				);
			} ),
		[
			watchOutcome,
			emitResponse.responseTypes.SUCCESS,
			emitResponse.responseTypes.ERROR,
			emitResponse.noticeContexts.PAYMENTS,
		]
	);

	useEffect( () => {
		const unsubscribe = onCheckoutSuccess(
			( { processingResponse, orderId } ) => {
				const outcomeDemandId =
					processingResponse?.paymentDetails?.edge_demand_id;

				// Blocks seeds the checkout store's `orderId` from the draft
				// order in the opening GET /wc/store/v1/checkout and never
				// updates it from the POST response, so on a fresh session it
				// is still 0 here and the wait below would be skipped
				// entirely — the shopper redirected to an order nobody had
				// waited on. process_payment() therefore sends the real id
				// back in the payment details (as a string, which is how the
				// Store API renders them), and that is preferred over the
				// store's copy. The observer's own value is kept only as the
				// fallback for a response that did not carry one.
				const detailsOrderId = parseInt(
					processingResponse?.paymentDetails?.edge_order_id,
					10
				);
				const outcomeOrderId =
					Number.isInteger( detailsOrderId ) && detailsOrderId > 0
						? detailsOrderId
						: orderId;

				// Someone else's order, or a page that has since remounted
				// against a different demand. Returning nothing lets Blocks
				// finish the checkout exactly as it would without us.
				if (
					'string' !== typeof outcomeDemandId ||
					! outcomeDemandId ||
					outcomeDemandId !== mountedDemand.current ||
					! outcomeOrderId
				) {
					return undefined;
				}

				return awaitOutcome( outcomeOrderId, outcomeDemandId );
			}
		);

		return () => unsubscribe();
	}, [ onCheckoutSuccess, awaitOutcome ] );

	// --- Resume a payment that was already in flight -------------------------
	//
	// Reached when the shopper reloads the checkout while a payment is still
	// settling. There is no checkout lifecycle to return an answer into here,
	// so outcomes are acted on directly.
	useEffect( () => {
		if ( ! resumeOrderId ) {
			return undefined;
		}

		return watchOutcome( resumeOrderId, null, ( status, response ) => {
			if ( 'succeeded' === status ) {
				if (
					'string' === typeof response.redirectUrl &&
					response.redirectUrl
				) {
					window.location.assign( response.redirectUrl );

					return true;
				}

				// Paid, but with nowhere to send the shopper. Nothing useful
				// can be done with that, so decline the answer and let the
				// wait run out into the message below.
				return false;
			}

			if ( 'failed' === status ) {
				// The order is failed on the server and will be reused, so a
				// fresh demand is safe to ask for and is the only way back to a
				// mounted card form. The decline goes in its own slot: it must
				// not block payment setup once that form is up.
				setResumeError( declineMessage( response ) );
				setResumeOrderId( null );
				setRetryToken( ( token ) => token + 1 );

				return true;
			}

			if ( 'timeout' === status ) {
				// Deliberately a dead end rather than a retry: the payment is
				// still out there, and the server would refuse a second demand
				// for as long as it is.
				setResumeOrderId( null );
				setFailure(
					__(
						'Your previous payment is still being processed. Please try again in a few minutes.',
						'edge-gateway'
					)
				);
			}

			return true;
		} );
	}, [ resumeOrderId, watchOutcome ] );

	// A wait must not outlive the component it belongs to. The resume wait is
	// cancelled by its own effect cleanup; the checkout one has no effect to
	// hang on, so it is cancelled here — resolving the promise Blocks is
	// holding rather than abandoning it, so the checkout never hangs on a
	// component that no longer exists.
	useEffect(
		() => () => {
			if ( abandonWait.current ) {
				abandonWait.current();
			}
		},
		[]
	);

	return (
		<div className="wc-edge-payment">
			{ settings.description && (
				<div
					className="wc-edge-payment__description"
					dangerouslySetInnerHTML={ { __html: settings.description } }
				/>
			) }

			{ ( failure || resumeError ) && (
				<div className="wc-edge-payment__error" role="alert">
					{ failure || resumeError }
				</div>
			) }

			{ /* Announced rather than shown: the form is locked while this is up,
			     so a screen reader has to be told the wait is the reason. */ }
			{ waitStage && (
				<div className="wc-edge-payment__status" role="status">
					{ 'slow' === waitStage
						? __(
								'This is taking longer than usual. Please keep this page open.',
								'edge-gateway'
						  )
						: __(
								'Waiting for your bank to confirm your payment…',
								'edge-gateway'
						  ) }
				</div>
			) }

			{ /* Kept as wide as the theme allows: the 3DS challenge is sized from
			     this element's offsetWidth, and a narrow container forces the
			     smallest, least usable challenge layout. */ }
			<div id={ containerId } style={ { width: '100%' } } />
		</div>
	);
};

/**
 * Editor preview.
 *
 * Deliberately static: the editor must not call the prepare endpoint or mount a
 * live payment iframe.
 */
const EdgeEditorPreview = () => (
	<div className="wc-edge-payment">
		{ settings.description && (
			<div dangerouslySetInnerHTML={ { __html: settings.description } } />
		) }
		<p>
			{ __(
				'The secure card form from Edge appears here at checkout.',
				'edge-gateway'
			) }
		</p>
	</div>
);

const EdgeLabel = ( { components } ) => {
	const { PaymentMethodLabel } = components;

	return <PaymentMethodLabel text={ label } />;
};

registerPaymentMethod( {
	name: 'edge',
	label: <EdgeLabel />,
	content: <EdgePaymentForm />,
	edit: <EdgeEditorPreview />,
	ariaLabel: label,
	canMakePayment: () => Boolean( settings.publishableKey ),
	supports: {
		features: settings.supports || [],
	},
} );
