<?php
/**
 * PayTR Direkt API istemcisi.
 *
 * Kaynak: https://dev.paytr.com/direkt-api (1. adım: ödeme isteği, 2. adım:
 * bildirim/callback), https://dev.paytr.com/direkt-api/bin-sorgulama-servisi,
 * https://dev.paytr.com/direkt-api/taksit-sorgulama.
 *
 * ÖNEMLİ (PCI-DSS): Bu sınıftaki hiçbir metot kart numarasını / CVC'yi
 * kalıcı bir yere (DB, transient, log) YAZMAZ. Kart alanları yalnızca
 * charge() içinde, tek bir HTTP isteği ömrü boyunca bellekte tutulur.
 */

namespace PaytrInlineCheckout;

defined( 'ABSPATH' ) || exit;

class Api {

	const PAYMENT_ENDPOINT      = 'https://www.paytr.com/odeme';
	const BIN_ENDPOINT          = 'https://www.paytr.com/odeme/api/bin-detail';
	const INSTALLMENT_ENDPOINT  = 'https://www.paytr.com/odeme/taksit-oranlari';

	/** @var array */
	protected $settings;

	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	protected function merchant_id() {
		return trim( (string) ( $this->settings['merchant_id'] ?? '' ) );
	}

	protected function merchant_key() {
		return trim( (string) ( $this->settings['merchant_key'] ?? '' ) );
	}

	protected function merchant_salt() {
		return trim( (string) ( $this->settings['merchant_salt'] ?? '' ) );
	}

	public function is_configured() {
		return $this->merchant_id() && $this->merchant_key() && $this->merchant_salt();
	}

	/**
	 * Ödeme (Direkt API 1. adım) hash'i.
	 * Sıra: merchant_id + user_ip + merchant_oid + email + payment_amount +
	 *       payment_type + installment_count + currency + test_mode + non_3d
	 */
	protected function payment_token( array $p ) {
		$str = $p['merchant_id'] . $p['user_ip'] . $p['merchant_oid'] . $p['email'] . $p['payment_amount']
			. $p['payment_type'] . $p['installment_count'] . $p['currency'] . $p['test_mode'] . $p['non_3d'];
		return base64_encode( hash_hmac( 'sha256', $str . $this->merchant_salt(), $this->merchant_key(), true ) );
	}

	/**
	 * Kartı PayTR'ye gönderir; dönen ham HTML'i (3D Secure ACS formu ya da
	 * hata sayfası) olduğu gibi geri döndürür. Bu HTML tarayıcıda bir iframe
	 * içine (srcdoc) yerleştirilip kullanıcıya gösterilecektir.
	 *
	 * @param \WC_Order $order
	 * @param array     $card  { owner, number, month, year, cvv } — asla saklanmaz.
	 * @param int       $installment
	 * @param string    $client_ip
	 * @param string    $ok_url
	 * @param string    $fail_url
	 * @return array{html:string}|\WP_Error
	 */
	public function charge( \WC_Order $order, array $card, $installment, $client_ip, $ok_url, $fail_url ) {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'paytr_not_configured', __( 'PayTR mağaza bilgileri (merchant_id/key/salt) girilmemiş.', 'paytr-inline-checkout' ) );
		}

		$currency_map = array( 'TRY' => 'TL', 'USD' => 'USD', 'EUR' => 'EUR', 'GBP' => 'GBP', 'RUB' => 'RUB' );
		$currency     = $currency_map[ get_woocommerce_currency() ] ?? 'TL';

		$amount_kurus = (int) round( (float) $order->get_total() * 100 );

		$basket = array();
		foreach ( $order->get_items() as $item ) {
			$basket[] = array(
				wp_strip_all_tags( $item->get_name() ),
				number_format( (float) ( $item->get_total() / max( 1, $item->get_quantity() ) ), 2, '.', '' ),
				$item->get_quantity(),
			);
		}
		if ( ! $basket ) {
			$basket[] = array( 'Sipariş #' . $order->get_id(), number_format( (float) $order->get_total(), 2, '.', '' ), 1 );
		}

		$test_mode = ! empty( $this->settings['test_mode'] ) && 'yes' === $this->settings['test_mode'] ? '1' : '0';

		$params = array(
			'merchant_id'        => $this->merchant_id(),
			'user_ip'            => substr( (string) $client_ip, 0, 39 ),
			'merchant_oid'       => $this->merchant_oid( $order ),
			'email'              => substr( (string) $order->get_billing_email(), 0, 100 ),
			'payment_amount'     => $amount_kurus,
			'payment_type'       => 'card',
			'installment_count'  => max( 0, (int) $installment ),
			'currency'           => $currency,
			'test_mode'          => $test_mode,
			'non_3d'             => '0', // her zaman 3D Secure zorunlu.
			'merchant_ok_url'    => $ok_url,
			'merchant_fail_url'  => $fail_url,
			'user_name'          => substr( trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ), 0, 60 ),
			'user_address'       => substr( trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() . ' ' . $order->get_billing_city() ), 0, 400 ) ?: 'Belirtilmedi',
			'user_phone'         => substr( (string) $order->get_billing_phone(), 0, 20 ) ?: '05000000000',
			'user_basket'        => base64_encode( wp_json_encode( $basket ) ),
			'debug_on'           => ! empty( $this->settings['debug_on'] ) && 'yes' === $this->settings['debug_on'] ? '1' : '0',
			'client_lang'        => 'tr',
			'cc_owner'           => substr( (string) $card['owner'], 0, 50 ),
			'card_number'        => preg_replace( '/\D/', '', (string) $card['number'] ),
			'expiry_month'       => str_pad( preg_replace( '/\D/', '', (string) $card['month'] ), 2, '0', STR_PAD_LEFT ),
			'expiry_year'        => preg_replace( '/\D/', '', (string) $card['year'] ),
			'cvv'                => preg_replace( '/\D/', '', (string) $card['cvv'] ),
		);

		if ( ! empty( $card['brand'] ) ) {
			$params['card_type'] = sanitize_key( $card['brand'] );
		}

		$params['paytr_token'] = $this->payment_token( $params );

		$response = wp_remote_post( self::PAYMENT_ENDPOINT, array(
			'timeout' => 25,
			'body'    => $params,
		) );

		// Kart alanlarını istekten hemen sonra bellekten temizle.
		unset( $params['card_number'], $params['cvv'], $params['expiry_month'], $params['expiry_year'], $params['cc_owner'], $card );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 || ! $body ) {
			return new \WP_Error( 'paytr_http', __( 'PayTR sunucusuna ulaşılamadı, lütfen tekrar deneyin.', 'paytr-inline-checkout' ) );
		}

		return array( 'html' => $body );
	}

	/**
	 * Sipariş başına deterministik ama tekil PayTR sipariş numarası.
	 * PayTR aynı merchant_oid ile tekrar denemeyi reddeder; bu yüzden her kart
	 * denemesinde YENİ bir oid üretiyoruz (order id + deneme sayacı).
	 */
	public function merchant_oid( \WC_Order $order ) {
		$attempt = (int) $order->get_meta( '_paytr_attempt' ) + 1;
		$order->update_meta_data( '_paytr_attempt', $attempt );
		$order->save_meta_data();
		$oid = 'WC' . $order->get_id() . 'A' . $attempt . wp_generate_password( 4, false, false );
		return preg_replace( '/[^A-Za-z0-9]/', '', $oid );
	}

	/**
	 * BIN sorgulama (kart numarasının ilk 6-8 hanesi) — banka/marka bilgisi.
	 *
	 * @param string $bin
	 * @return array|\WP_Error
	 */
	public function bin_detail( $bin ) {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'paytr_not_configured', __( 'PayTR mağaza bilgileri girilmemiş.', 'paytr-inline-checkout' ) );
		}
		$bin = preg_replace( '/\D/', '', (string) $bin );
		if ( strlen( $bin ) < 6 ) {
			return new \WP_Error( 'paytr_bin_short', __( 'Geçersiz BIN.', 'paytr-inline-checkout' ) );
		}

		// BIN -> banka/marka eşlemesi pratikte değişmez; önbellekleme hem PayTR'ye
		// gereksiz istek göndermeyi hem de bu ucun kötüye kullanılarak PayTR'nin
		// servisine yük bindirilmesini (BIN enumeration) engeller.
		$cache_key = 'paytr_inline_bin_' . md5( $this->merchant_id() . '|' . $bin );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$hash_str = $bin . $this->merchant_id() . $this->merchant_salt();
		$token    = base64_encode( hash_hmac( 'sha256', $hash_str, $this->merchant_key(), true ) );

		$response = wp_remote_post( self::BIN_ENDPOINT, array(
			'timeout' => 12,
			'body'    => array(
				'merchant_id' => $this->merchant_id(),
				'bin_number'  => $bin,
				'paytr_token' => $token,
			),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'paytr_bin_bad_response', __( 'BIN sorgulama yanıtı okunamadı.', 'paytr-inline-checkout' ) );
		}
		set_transient( $cache_key, $data, 12 * HOUR_IN_SECONDS );
		return $data;
	}

	/**
	 * Taksit oranları — banka/kart markasına göre komisyon oranları.
	 * 30 dakika transient ile önbelleklenir (oranlar günlük değişebilir).
	 *
	 * @return array|\WP_Error
	 */
	public function installment_rates() {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'paytr_not_configured', __( 'PayTR mağaza bilgileri girilmemiş.', 'paytr-inline-checkout' ) );
		}

		$cache_key = 'paytr_inline_rates_' . md5( $this->merchant_id() );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$request_id = substr( 'req' . time(), 0, 32 );
		$hash_str   = $this->merchant_id() . $request_id . $this->merchant_salt();
		$token      = base64_encode( hash_hmac( 'sha256', $hash_str, $this->merchant_key(), true ) );

		$response = wp_remote_post( self::INSTALLMENT_ENDPOINT, array(
			'timeout' => 12,
			'body'    => array(
				'merchant_id' => $this->merchant_id(),
				'request_id'  => $request_id,
				'paytr_token' => $token,
			),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['oranlar'] ) ) {
			// PayTR bazen sebebi err_msg ile döner (ör. "Magaza API yetkisi bulunmuyor.") —
			// mevcutsa aynen ilet ki admin/test eden kişi asıl sebebi görsün.
			$reason = ! empty( $data['err_msg'] ) ? (string) $data['err_msg'] : __( 'Taksit oranları alınamadı.', 'paytr-inline-checkout' );
			return new \WP_Error( 'paytr_rates_bad_response', $reason );
		}

		set_transient( $cache_key, $data, 30 * MINUTE_IN_SECONDS );
		return $data;
	}

	/**
	 * Bildirim (callback) hash doğrulaması.
	 * Sıra: merchant_oid + merchant_salt + status + total_amount
	 */
	public function verify_notification_hash( array $post ) {
		if ( empty( $post['merchant_oid'] ) || empty( $post['hash'] ) || ! isset( $post['status'], $post['total_amount'] ) ) {
			return false;
		}
		$str      = $post['merchant_oid'] . $this->merchant_salt() . $post['status'] . $post['total_amount'];
		$expected = base64_encode( hash_hmac( 'sha256', $str, $this->merchant_key(), true ) );
		return hash_equals( $expected, (string) $post['hash'] );
	}
}
