initialize();

// Create a Checkout Session
async function initialize() {
	const fetchPubKey = async () => {
		const response = await fetch("./?c=customer&a=stripePubKey", {
			headers: new Headers({ 'content-type': 'application/json' }),
		});
		const { stripePubKey } = await response.json();
		return stripePubKey;
	};

	const stripe = Stripe(await fetchPubKey());

	const fetchClientSecret = async () => {
		const response = await fetch("./?c=customer&a=checkoutPost", {
			method: "POST",
			headers: {
				'Accept': 'application/json',
				'Content-Type': 'application/json; charset=UTF-8',
			},
			body: JSON.stringify({
				'_csrf': context.csrf,
			}),
		});
		const { clientSecret } = await response.json();
		return clientSecret;
	};

	const checkout = await stripe.initEmbeddedCheckout({
		fetchClientSecret,
	});

	// Mount Checkout
	checkout.mount('#checkout');
}
