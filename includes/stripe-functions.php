<?php

/**
 * Display the Stripe Form in a Thickbox Pop-up
 *
 * @param $atts array Undefined, have not found any use yet
 * @return string Form Pop-up Link (wrapped in <a></a>)
 *
 * @since 1.3
 *
 */
function wp_stripe_shortcode($atts) {

	$options = get_option('wp_stripe_options');

	$settings = '?keepThis=true&TB_iframe=true&height=580&width=400';
	$path = WP_STRIPE_PATH . '/includes/stripe-iframe.php' . $settings;
	$count = 1;

	if ($options['stripe_modal_ssl'] == 'Yes') {
		$path = str_replace("http://", "https://", $path, $count);
	}

	extract(shortcode_atts(array(
				'cards' => 'true'
					), $atts));

	if ($cards == 'true') {
		$payments = '<div id="wp-stripe-types"></div>';
	}

	return '<a class="thickbox" id="wp-stripe-modal-button" title="' . $options['stripe_header'] . '" href="' . $path . '">' . $options['stripe_header'] . '</a>' . $payments;
}

add_shortcode('wp-stripe', 'wp_stripe_shortcode');

function wp_stripe_embedded_shortcode($atts) {
	extract(shortcode_atts(array(
				'campaign' => '',
				'campaign_default' => '',
					), $atts));

	$options = get_option('wp_stripe_options');

	$settings = "?keepThis=true&amp;TB_iframe=true&amp;height=580&amp;width=400&amp;campaign={$campaign}&amp;campaign-default={$campaign_default}";
	$path = WP_STRIPE_PATH . '/includes/stripe-iframe.php' . $settings;
	$count = 1;

	if ($options['stripe_modal_ssl'] == 'Yes') {
		$path = str_replace("http://", "https://", $path, $count);

		if ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') == false) {
			header('HTTP/1.1 301 Moved Permanently');
			header('Location: https://' . $_SERVER['SERVER_NAME'].$_SERVER['REQUEST_URI']);
			exit;
		}
	}

	return '<iframe class="wp-stripe-embedded-frame" src="' . $path . '" scrollng="no" frameborder="0" width="440" height="700" style="overflow: hidden;"></iframe>';
}

add_shortcode('wp-stripe-embedded', 'wp_stripe_embedded_shortcode');

/**
 * Display Legacy Stripe form in-line
 *
 * @param $atts array Undefined, have not found any use yet
 * @return string Form / DOM Content
 *
 * @since 1.3
 *
 */
function wp_stripe_shortcode_legacy($atts) {

	return wp_stripe_form();
}

add_shortcode('wp-legacy-stripe', 'wp_stripe_shortcode_legacy');

/**
 * Create Charge using Stripe PHP Library
 *
 * @param $amount int transaction amount in cents (i.e. $1 = '100')
 * @param $card string
 * @param $description string
 * @return array
 *
 * @since 1.0
 *
 */
function wp_stripe_charge($amount, $card, $name, $description) {

	/*
	 * Currency - All amounts must be denominated in USD when creating charges with Stripe — the currency conversion happens automatically
	 */

	$currency = 'usd';

	/*
	 * Card - Token from stripe.js is provided (not individual card elements)
	 */

	$charge = array(
		'card' => $card,
		'amount' => $amount,
		'currency' => $currency,
	);

	if ($description) {
		$charge['description'] = $description;
	}

	$response = Stripe_Charge::create($charge);

	return $response;
}

/**
 * 3-step function to Process & Save Transaction
 *
 * 1) Capture POST
 * 2) Create Charge using wp_stripe_charge()
 * 3) Store Transaction in Custom Post Type
 *
 * @since 1.0
 *
 */
add_action('wp_ajax_wp_stripe_charge_initiate', 'wp_stripe_charge_initiate');
add_action('wp_ajax_nopriv_wp_stripe_charge_initiate', 'wp_stripe_charge_initiate');

function wp_stripe_charge_initiate() {

	// Security Check

	if (!wp_verify_nonce($_POST['nonce'], 'wp-stripe-nonce')) {
		die('Nonce verification failed');
	}

	// Define/Extract Variables

	$name = $_POST['wp_stripe_name'];
	$email = $_POST['wp_stripe_email'];
	$amount = str_replace('$', '', $_POST['wp_stripe_amount']) * 100;
	$card = $_POST['stripeToken'];

	//make sure the amount being charged meets the minimum allowed
	$options = get_option('wp_stripe_options');
	
	if ($amount < ($options['stripe_payment_minimum'] * 100)) {
		$result = '<div class="wp-stripe-notification wp-stripe-failure">' . __('Oops, something went wrong', 'wp-stripe') . ' (Sorry. The minimum payment allowed using this system is $' . $options['stripe_payment_minimum'] . ')</div>';
		do_action('wp_stripe_post_fail_charge', $email, 'Sorry. The minimum payment allowed using this system is $' . $options['stripe_payment_minimum']);
	} else {

		$stripe_comment = __("Transaction type: ", 'wp-stripe') . __("Donation", 'wp-stripe') . "\n";
		$stripe_comment .= __('E-mail: ', 'wp-stipe') . $_POST['wp_stripe_email'] . "\n";

		//Find the campaign
		if (isset($_POST['campaignId'])
				&& is_numeric($_POST['campaignId'])) {

			$campaign = get_post_custom($_POST['campaignId']);

			//is this a valid campaign?
			if (isset($campaign["wp-stripe-campaign-raised"][0])) {
				$campaignId = $_POST['campaignId'];
				$campaignName = get_the_title($campaignId);

				$stripe_comment .= __("Campaign name: ", 'wp-stripe') . "'" . $campaignName . "'\n";
				$stripe_comment .= __("Campaign ID: ", 'wp-stripe') . $campaignId . "\n";
			} else {
				$campaignId = '';
				$campaignName = '';
			}
		}


		if (!$_POST['wp_stripe_comment']) {
			$widget_comment = '';
			$public = 'NO';
		} else {
			$stripe_comment .= __("Comment: ", 'wp-stripe') . $_POST['wp_stripe_comment'] . "\n";
			$widget_comment = $_POST['wp_stripe_comment'];
			$public = 'YES';
		}

		// Create Charge

		try {

			$response = wp_stripe_charge($amount, $card, $name, $stripe_comment);

			$id = $response->id;
			$amount = ($response->amount) / 100;
			$currency = $response->currency;
			$created = $response->created;
			$live = $response->livemode;
			$paid = $response->paid;
			$fee = $response->fee;

			$options = get_option('wp_stripe_options');

			//redirect using JavaScript if a success URL is set
			if (isset($options['action_success_redirect'])
					&& trim($options['action_success_redirect']) !== '') {

				$result = '<div class="wp-stripe-notification wp-stripe-success"> ' . __('Success! Thank you. Please wait while the page loads.', 'wp-stripe') . '</div>
					<script type="text/javascript">parent.window.location = "' . $options['action_success_redirect'] . '";</script>';
			} else {
				$result = '<div class="wp-stripe-notification wp-stripe-success"> ' . __('Success, you just transferred ', 'wp-stripe') . '<span class="wp-stripe-currency">' . $currency . '</span> ' . $amount . ' !</div>';
			}

			// Save Charge

			if ($paid == true) {

				$new_post = array(
					'ID' => '',
					'post_type' => 'wp-stripe-trx',
					'post_author' => 1,
					'post_content' => $widget_comment,
					'post_title' => $id,
					'post_status' => 'publish',
				);

				$post_id = wp_insert_post($new_post);

				// Define Livemode

				if ($live) {
					$live = 'LIVE';
				} else {
					$live = 'TEST';
				}

				// Update Meta

				update_post_meta($post_id, 'wp-stripe-public', $public);
				update_post_meta($post_id, 'wp-stripe-name', $name);
				update_post_meta($post_id, 'wp-stripe-email', $email);

				update_post_meta($post_id, 'wp-stripe-live', $live);
				update_post_meta($post_id, 'wp-stripe-date', $created);
				update_post_meta($post_id, 'wp-stripe-amount', $amount);
				update_post_meta($post_id, 'wp-stripe-currency', strtoupper($currency));
				update_post_meta($post_id, 'wp-stripe-fee', $fee);

				//is this a valid campaign?
				if (isset($campaign["wp-stripe-campaign-raised"][0])) {
					//update the campaign total. Possible collision if two people donate at the same exact time, the total might get mixed up. 
					update_post_meta($_POST['campaignId'], 'wp-stripe-campaign-raised', $campaign["wp-stripe-campaign-raised"][0] + $amount);

					//assign the campaignId to this donation.
					update_post_meta($post_id, 'wp-stripe-campaign', $_POST['campaignId']);
				}
				// Hook

				do_action('wp_stripe_post_successful_charge', $response, $email, $stripe_comment);

				// Update Project
				// wp_stripe_update_project_transactions( 'add', $project_id , $post_id );
				//send success email
				if (isset($options['email_confirmation_send'])
						&& $options['email_confirmation_send'] == 'Yes') {

					$headers[] = 'From: ' . $options['email_confirmation_from_name'] . ' <' . $options['email_confirmation_from_email'] . '>';
					$headers[] = 'Bcc: ' . $options['email_confirmation_from_name'] . ' <' . $options['email_confirmation_from_email'] . '>';

					//replace amount placeholder in the message
					$message = $options['email_confirmation_message'];
					$message = str_ireplace('%Amount%', $amount, $message);
					$message = str_ireplace('%Name%', $name, $message);

					wp_mail($email, $options['email_confirmation_subject'], $message, $headers);
				}
			}

			// Error
		} catch (Exception $e) {

			$result = '<div class="wp-stripe-notification wp-stripe-failure">' . __('Oops, something went wrong', 'wp-stripe') . ' (' . $e->getMessage() . ')</div>';
			do_action('wp_stripe_post_fail_charge', $email, $e->getMessage());
		}
	}
	// Return Results to JS

	header("Content-Type: application/json");
	echo json_encode($result);
	exit;
}

?>