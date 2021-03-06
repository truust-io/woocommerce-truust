<?php

namespace Truust;

defined( 'ABSPATH' ) || exit;

class Request
{
	private $api_key;
	/**
	 * get_return_url($order) returns a url with the following format:
	 * http://localhost:8001/?page_id=8&order-received=21&key=wc_order_DIr56QeBwbcuM
	 *
	 * wc_get_checkout_url() returns a url with the following format:
	 * http://localhost:8001/?page_id=8
	 *
	 * we append '?status=failed&key=' . $order->get_order_key() to indicate the payment failed
	 *
	 */
	public function send($order)
	{
		$this->api_key = truust('gateway')->settings['api_key'];

		if (!$this->api_key) {
			return;
		}

		$separator = '';
		$name = '';
		foreach ($order->get_items() as $item) {
			$name = $name . $separator . $item['name'];
			$separator = ' | ';
		}

		$seller_id = $this->create_customer(truust('gateway')->settings['email']);
		$buyer_id = $this->create_customer(
			$order->billing_email,
			phone_prefix($order->get_billing_country()),
			$order->get_billing_phone()
		);

		if (!$seller_id || !$buyer_id) {
			return false;
		}

		$curl = curl_init();

		curl_setopt_array($curl, [
			CURLOPT_URL => api_base_url($this->api_key) . '/2.0/orders',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => '',
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 0,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => 'POST',
			CURLOPT_POSTFIELDS => [
				'buyer_id' => $buyer_id,
				'seller_id' => $seller_id,
				'name' => mb_strimwidth($name, 0, 120),
				'value' => $order->get_total(),
				'tag' => $order->get_order_number(),
				'seller_confirmed_url' => truust('gateway')->settings['seller_confirmed_url'],
				'seller_denied_url' => truust('gateway')->settings['seller_denied_url'],
				'buyer_confirmed_url' => html_entity_decode(truust('gateway')->get_return_url($order)),
				'buyer_denied_url' => wc_get_checkout_url() . '&status=failed&key=' . $order->get_order_key(),
			],
			CURLOPT_HTTPHEADER => [
				'Accept: application/json',
				'Authorization: Bearer ' . $this->api_key,
			],
		]);

		$response = curl_exec($curl);
		$response = remove_utf8_bom($response);
		$response = json_decode($response, true);

		curl_close($curl);

		if (isset($response['data'])) {
			$order->update_status('pending', __($response['status_nicename'], config('text-domain')));

			return $this->create_payin($response['data']);
		}

		return false;
	}

	private function create_payin($data)
	{
		$curl = curl_init();

		curl_setopt_array($curl, array(
			CURLOPT_URL => api_base_url($this->api_key) . '/2.0/payins',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => "",
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 0,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => 'POST',
			CURLOPT_POSTFIELDS => [
				'order_id' => $data['id'],
				'type' => 'REDSYS_V2'
			],
			CURLOPT_HTTPHEADER => [
				'Accept: application/json',
				'Authorization: Bearer ' . $this->api_key,
			],
		));

		$response = curl_exec($curl);
		$response = remove_utf8_bom($response);
		$response = json_decode($response, true);

		curl_close($curl);

		if (isset($response['data']) && isset($response['data']['direct_link'])) {
			return [
				'truust_order_id' => $data['id'],
				'order_name' => $data['name'],
				'buyer_link' => $data['buyer_link'],
				'redirect' => $response['data']['direct_link'],
			];
		}

		return false;
	}

	private function create_customer($email, $prefix = null, $phone = null)
	{
		global $wpdb;

		$table = $wpdb->prefix . 'truust_customers';
		$customer = $wpdb->get_row('SELECT * FROM ' . $table . ' WHERE email = "' . $email . '"');

		if ($customer) {
			return $customer->truust_customer_id;
		} else {
			$curl = curl_init();
			$post_fields = ['email' => $email];

			if (! is_null($prefix) && ! is_null($phone))
			{
				$post_fields['prefix'] = $prefix;
				$post_fields['phone'] = $phone;
			}

			curl_setopt_array($curl, array(
				CURLOPT_URL => api_base_url($this->api_key) . '/2.0/customers',
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_ENCODING => "",
				CURLOPT_MAXREDIRS => 10,
				CURLOPT_TIMEOUT => 0,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
				CURLOPT_CUSTOMREQUEST => "POST",
				CURLOPT_POSTFIELDS => $post_fields,
				CURLOPT_HTTPHEADER => [
					'Accept: application/json',
					'Authorization: Bearer ' . $this->api_key,
				],
			));

			$response = curl_exec($curl);
			$response = remove_utf8_bom($response);
			$response = json_decode($response, true);

			if (isset($response['data'])) {
				$customer_id = $response['data']['id'];

				$table = $wpdb->prefix . 'truust_customers';
				$data = [
					'email' => $email,
					'truust_customer_id' => $customer_id
				];

				$format = array('%s', '%s');
				$wpdb->insert($table, $data, $format);

				return $customer_id;
			}
		}

		return false;
	}
	public function truncateTable($tableToTruncate)
	{
		global $wpdb;
		$wpdb->query('TRUNCATE TABLE ' . $tableToTruncate);
		return true;
	}
}
