<?php
declare(strict_types=1);

/**
 * This controller handles action about authentication.
 */
class FreshRSS_customer_Controller extends FreshRSS_ActionController {
	public function payAction(): void {
		if (FreshRSS_Auth::hasAccess()) {
			Minz_Request::forward(['c' => 'index', 'a' => 'index'], true);
		}

		if (max_registrations_reached()) {
			Minz_Error::error(403);
		}
		require '../../vendor/autoload.php';
		\Stripe\Stripe::setAppInfo(
			"news.decent.im",
			"0.0.1",
			"https://github.com/decent-im/FreshRSS"
		);
		\Stripe\Stripe::setApiKey(FreshRSS_Context::systemConf()->stripe_secret_key);

		// Create new Checkout Session for the order
		// Other optional params include:
		// [billing_address_collection] - to display billing address details on the page
		// [customer] - if you have an existing Stripe Customer ID
		// [payment_intent_data] - lets capture the payment later
		// [customer_email] - lets you prefill the email input in the form
		// [automatic_tax] - to automatically calculate sales tax, VAT and GST in the checkout page
		// For full details see https://stripe.com/docs/api/checkout/sessions/create

		// ?session_id={CHECKOUT_SESSION_ID} means the redirect will have the session ID set as a query param
		$success_url = Minz_Url::display(
			['c' => 'customer', 'a' => 'issueTicket'],
			/*encoding*/'raw', /*absolute=*/true);
		$cancel_url = Minz_Url::display(
			['c' => 'customer', 'a' => 'payFailed'],
			/*encoding*/'raw', /*absolute=*/true);
		$checkout_session = \Stripe\Checkout\Session::create([
			'success_url' => $success_url . '&session_id={CHECKOUT_SESSION_ID}',
			'cancel_url' => $cancel_url,
			'mode' => 'subscription',
			// 'ui_mode' => 'embedded', // TODO for another day
			'line_items' => [[
				'price' => FreshRSS_Context::systemConf()->stripe_price_id,
				'quantity' => 1,
			]],
			'subscription_data' => [
				'trial_period_days' => 30,
			],
		]);

		header($_SERVER["SERVER_PROTOCOL"] . " 303 Proceed to payment page");
		header("Location: " . $checkout_session->url);
		exit;
	}

	// BEGIN Stripe EMBEDDED checkout
	// https://docs.stripe.com/checkout/embedded/quickstart
	// checkout.php part
	// stage 1: renders HTML DOM which loads Stripe JS
	public function checkoutAction(): void {
		FreshRSS_View::appendTitle(" - Newsreader service offering");
		// Let logged in user read the page, but disable payment functionality.
		if (!FreshRSS_Auth::hasAccess()) {
			FreshRSS_View::appendScript("https://js.stripe.com/v3/", /*cond=*/false, /*defer=*/false, /*async=*/false);
			FreshRSS_View::appendScript(Minz_Url::display('/scripts/checkout.js'), /*cond=*/false, /*defer=*/true, /*async=*/false);
		}
	}

	public function stripePubKeyAction(): void {
		header('Content-Type: application/json; charset=UTF-8');
		echo json_encode(array('stripePubKey' => FreshRSS_Context::systemConf()->stripe_public_key));
		exit;
	}

	// stage 2: this handles POST request raised by the browser as it loads Stripe JS
	public function checkoutPostAction(): void {
		assert(isset($_POST));
		if (FreshRSS_Auth::hasAccess()) {
			Minz_Error::error(403, "Don't buy another account while logged in");
		}
		if (max_registrations_reached()) {
			Minz_Error::error(403, 'Max registrations reached');
		}
		require '../../vendor/autoload.php';
		\Stripe\Stripe::setAppInfo(
			"news.decent.im",
			"0.0.2",
			"https://github.com/decent-im/FreshRSS"
		);
		\Stripe\Stripe::setApiKey(FreshRSS_Context::systemConf()->stripe_secret_key);

		// ?session_id={CHECKOUT_SESSION_ID} means the redirect will have the session ID set as a query param
		$return_url = Minz_Url::display(
			['c' => 'customer', 'a' => 'issueTicket'],
			/*encoding*/'raw', /*absolute=*/true);
		$checkout_session = \Stripe\Checkout\Session::create([
			'return_url' => $return_url . '&session_id={CHECKOUT_SESSION_ID}',
			'mode' => 'subscription',
			'ui_mode' => 'embedded',
			'line_items' => [[
				'price' => FreshRSS_Context::systemConf()->stripe_price_id,
				'quantity' => 1,
			]],
			'subscription_data' => [
				'trial_period_days' => 30,
			],
		]);
		header('Content-Type: application/json');
		echo json_encode(array('clientSecret' => $checkout_session->client_secret));
		exit;
	}
	// END Stripe EMBEDDED checkout

	public function issueTicketAction(): void {
		require '../../vendor/autoload.php';
		\Stripe\Stripe::setAppInfo(
		  "news.decent.im",
		  "0.0.1",
		  "https://github.com/decent-im/FreshRSS"
		);
		\Stripe\Stripe::setApiKey(FreshRSS_Context::systemConf()->stripe_secret_key);

		// Fetch the Checkout Session to display the JSON result on the success page
		$checkout_session_id = $_GET['session_id'];
		$checkout_session = \Stripe\Checkout\Session::retrieve($checkout_session_id);
		if (!$checkout_session) {
			error_log('Retrieving checkout session failed: ' . $checkout_session_id);
			header($_SERVER["SERVER_PROTOCOL"] . " 400 Bad checkout session id");
			exit;
		}

		// Format as JSON for the demo.
		$session_json = json_encode($checkout_session, JSON_PRETTY_PRINT);
		error_log($session_json);

		// Make sure we got paid
		if ($checkout_session->payment_status != 'paid') {
			error_log('In checkout session ' . $checkout_session_id . ', payment intent status is unexpectedly ' . $checkout_session->payment_status);
			header($_SERVER["SERVER_PROTOCOL"] . " 400 Checkout session payment status indicates it wasn't paid");
			exit;
		}

		// precondition:
		// checkout_session is a valid object, this means it's genuinely been processed by Stripe
		// the remote party is buyer's browser
		//
		// postcondition:
		// buyer is enabled to register an account
		// - they get a single-use secret "ticket" (a string)
		// - an extraneous page refresh or reopen must not break the ability to use ticket
		// - code must forbid multiple registrations with the same "ticket"
		// - TODO if paid but not registered in 15 minutes?, email them
		// - TODO tickets expire. after 30 days?
		//
		// invariants:
		// - registration is disabled for unpaid accounts
		// - admin can register accounts manually

		// random string, printable, no escaping needed:

		$ticket = $checkout_session_id;
		if (!is_dir(join_path(DATA_PATH, 'tickets'))) {
			mkdir(join_path(DATA_PATH, 'tickets'));
		}
		$ticket_file_path = join_path(DATA_PATH, 'tickets', $ticket);
		$fh = fopen($ticket_file_path, 'x'/*create, fail if exists*/);
		if ($fh === false) {
			error_log('ticket file ' . $ticket_file_path . ' already exists');
			// TODO show some error to the remote user
			exit;
		}
		fclose($fh);

		header("HTTP/1.1 303 Proceed to register using your ticket");
		// TODO prefill email (low priority)
		$forward_location = Minz_Url::display(
			['c' => 'auth', 'a' => 'register', 'params' => ['ticket' => $ticket]],
			/*encoding*/'raw', /*absolute=*/true);
		header("Location: " . $forward_location);
		exit;
	}
}
