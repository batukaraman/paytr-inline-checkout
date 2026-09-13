<?php
/**
 * PayTR inline checkout orkestrasyonu: gateway kaydı, assets, AJAX uçları
 * (BIN/taksit sorgulama, 3D Secure HTML teslimi, durum sorgulama) ve
 * PayTR bildirim (webhook) işleyicisi.
 */

namespace PaytrInlineCheckout;

defined( 'ABSPATH' ) || exit;

class Controller {

	/** @var Controller */
	private static $instance;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );

		add_action( 'wp_ajax_paytr_inline_bin', array( $this, 'ajax_bin_lookup' ) );
		add_action( 'wp_ajax_nopriv_paytr_inline_bin', array( $this, 'ajax_bin_lookup' ) );

		add_action( 'wp_ajax_paytr_inline_get_3ds', array( $this, 'ajax_get_3ds' ) );
		add_action( 'wp_ajax_nopriv_paytr_inline_get_3ds', array( $this, 'ajax_get_3ds' ) );

		add_action( 'wp_ajax_paytr_inline_status', array( $this, 'ajax_status' ) );
		add_action( 'wp_ajax_nopriv_paytr_inline_status', array( $this, 'ajax_status' ) );

		add_action( 'wp_ajax_paytr_inline_all_rates', array( $this, 'ajax_all_rates' ) );
		add_action( 'wp_ajax_nopriv_paytr_inline_all_rates', array( $this, 'ajax_all_rates' ) );

		// PayTR Mağaza Paneli -> Bildirim URL: /?wc-api=paytr_inline_notify
		add_action( 'woocommerce_api_paytr_inline_notify', array( $this, 'handle_notify' ) );

		// "Siparişlerim"de yarım kalan denemelerin Öde/İptal aksiyonlarını gizle.
		add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'hide_stub_actions' ), 10, 2 );

		add_action( 'init', array( $this, 'maybe_schedule_gc' ) );
		add_action( 'paytr_inline_gc', array( $this, 'run_gc' ) );
	}

	/* ------------------------------------------------------------------ */

	public function register_gateway( $methods ) {
		$methods[] = Gateway::class;
		return $methods;
	}

	protected function gateway() {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return null;
		}
		$all = WC()->payment_gateways()->payment_gateways();
		return isset( $all[ Gateway::ID ] ) ? $all[ Gateway::ID ] : null;
	}

	protected function api() {
		$gw = $this->gateway();
		if ( $gw instanceof Gateway ) {
			return new Api( $gw->api_settings() );
		}
		// Admin/webhook bağlamında gateway nesnesi kurulu olmayabilir; ayarları doğrudan oku.
		$opts = get_option( 'woocommerce_' . Gateway::ID . '_settings', array() );
		return new Api( array(
			'merchant_id'   => $opts['merchant_id'] ?? '',
			'merchant_key'  => $opts['merchant_key'] ?? '',
			'merchant_salt' => $opts['merchant_salt'] ?? '',
			'test_mode'     => $opts['test_mode'] ?? 'no',
			'debug_on'      => $opts['debug_on'] ?? 'no',
		) );
	}

	/* ------------------------------------------------------------------ */
	/*  Assets                                                            */
	/* ------------------------------------------------------------------ */

	public function enqueue() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		if ( is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'order-pay' ) ) {
			return;
		}

		wp_enqueue_style(
			'paytr-inline-checkout',
			PAYTR_INLINE_URL . 'assets/css/inline-checkout.css',
			array(),
			PAYTR_INLINE_VER
		);

		wp_enqueue_script(
			'paytr-inline-checkout',
			PAYTR_INLINE_URL . 'assets/js/inline-checkout.js',
			array( 'jquery', 'wc-checkout' ),
			PAYTR_INLINE_VER,
			true
		);

		wp_localize_script( 'paytr-inline-checkout', 'paytrInline', array(
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'checkoutUrl' => class_exists( 'WC_AJAX' ) ? \WC_AJAX::get_endpoint( 'checkout' ) : wc_get_checkout_url(),
			'nonce'       => wp_create_nonce( 'paytr_inline' ),
			'method'      => Gateway::ID,
			'i18n'    => array(
				'needFields'   => __( 'Kart bilgilerinizi girin.', 'paytr-inline-checkout' ),
				'invalidCard'  => __( 'Kart numarası geçersiz görünüyor.', 'paytr-inline-checkout' ),
				'loading3ds'   => __( '3D Secure doğrulaması yükleniyor…', 'paytr-inline-checkout' ),
				'waiting'      => __( 'Ödeme onayı bekleniyor…', 'paytr-inline-checkout' ),
				'declined'     => __( 'Ödeme onaylanmadı. Lütfen kart bilgilerinizi kontrol edip tekrar deneyin.', 'paytr-inline-checkout' ),
				'timeout'      => __( 'Ödeme onayı zaman aşımına uğradı. Lütfen tekrar deneyin.', 'paytr-inline-checkout' ),
				'generic'      => __( 'Bir hata oluştu. Lütfen tekrar deneyin.', 'paytr-inline-checkout' ),
				'noInstallment'=> __( 'Tek Çekim', 'paytr-inline-checkout' ),
				'installmentsPlaceholder' => __( 'Taksit seçenekleri kart numaranızı girdikten sonra görünecektir.', 'paytr-inline-checkout' ),
				'noInstallments' => __( 'Bu mağaza için herhangi bir taksit seçeneği bulunmamaktadır.', 'paytr-inline-checkout' ),
			),
		) );
	}

	/* ------------------------------------------------------------------ */
	/*  AJAX: BIN / taksit sorgulama                                      */
	/* ------------------------------------------------------------------ */

	public function ajax_bin_lookup() {
		check_ajax_referer( 'paytr_inline', 'nonce' );

		if ( $this->rate_limited( 'bin' ) ) {
			wp_send_json_error( array( 'message' => __( 'Çok fazla istek. Lütfen birkaç saniye sonra tekrar deneyin.', 'paytr-inline-checkout' ) ) );
		}

		$bin = isset( $_POST['bin'] ) ? preg_replace( '/\D/', '', wp_unslash( $_POST['bin'] ) ) : '';
		if ( strlen( $bin ) < 6 ) {
			wp_send_json_error( array( 'message' => __( 'Geçersiz kart numarası.', 'paytr-inline-checkout' ) ) );
		}

		$api = $this->api();

		$detail = $api->bin_detail( $bin );
		if ( is_wp_error( $detail ) ) {
			wp_send_json_error( array( 'message' => $detail->get_error_message() ) );
		}
		$total = $this->cart_total();

		if ( isset( $detail['status'] ) && 'success' !== $detail['status'] ) {
			wp_send_json_success( array( 'brand' => '', 'bank' => '', 'installments' => array(), 'total' => $total ) );
		}

		$brand = isset( $detail['brand'] ) ? strtolower( (string) $detail['brand'] ) : '';
		$bank  = isset( $detail['bank'] ) ? (string) $detail['bank'] : '';

		$rates        = $api->installment_rates();
		$installments = is_wp_error( $rates ) ? array() : $this->compute_installments( $rates, $brand, $total );

		wp_send_json_success( array(
			'brand'        => $brand,
			'bank'         => $bank,
			'installments' => $installments,
			'total'        => $total,
		) );
	}

	/* ------------------------------------------------------------------ */
	/*  AJAX: tüm markalar için taksit tablosu ("Tüm Taksit Seçeneklerini Göster") */
	/* ------------------------------------------------------------------ */

	public function ajax_all_rates() {
		check_ajax_referer( 'paytr_inline', 'nonce' );

		if ( $this->rate_limited( 'all_rates' ) ) {
			wp_send_json_error( array( 'message' => __( 'Çok fazla istek. Lütfen birkaç saniye sonra tekrar deneyin.', 'paytr-inline-checkout' ) ) );
		}

		$api   = $this->api();
		$rates = $api->installment_rates();
		if ( is_wp_error( $rates ) ) {
			wp_send_json_error( array( 'message' => $rates->get_error_message() ) );
		}
		if ( empty( $rates['oranlar'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Taksit oranları alınamadı.', 'paytr-inline-checkout' ) ) );
		}

		$total = $this->cart_total();
		$out   = array();
		foreach ( (array) $rates['oranlar'] as $brand => $brand_rates ) {
			$installments = $this->compute_installments( $rates, $brand, $total );
			if ( $installments ) {
				$out[] = array(
					'brand'        => $brand,
					'installments' => $installments,
				);
			}
		}

		wp_send_json_success( array( 'brands' => $out ) );
	}

	protected function cart_total() {
		return ! empty( WC()->cart ) ? (float) WC()->cart->get_total( 'edit' ) : 0;
	}

	/**
	 * Basit IP başına hız sınırlama — BIN/taksit sorgulama uçları PayTR'nin
	 * kendi servislerini çağırdığı için (önbelleklenmemiş ilk istekler)
	 * kötüye kullanımla (BIN enumeration, servis şişirme) PayTR'ye yük
	 * bindirilmesini engeller. 20 istek / 10 saniye, IP+uç başına.
	 *
	 * @param string $bucket
	 * @return bool true ise istek reddedilmeli.
	 */
	protected function rate_limited( $bucket ) {
		$ip  = \WC_Geolocation::get_ip_address();
		$key = 'paytr_inline_rl_' . $bucket . '_' . md5( $ip );
		$hit = (int) get_transient( $key );
		if ( $hit >= 20 ) {
			return true;
		}
		set_transient( $key, $hit + 1, 10 );
		return false;
	}

	/**
	 * "oranlar" PayTR panelinde tanımlı vade farkı yüzdesidir. Direkt API'de
	 * PayTR komisyonu kendisi eklemez — üye işyeri "peşin fiyatına taksit"
	 * mantığıyla tutarı kendisi şişirip göndermek zorundadır (bkz. Api::gross_up,
	 * PayTR desteğinin doğruladığı formül: TUTAR / ((100-ORAN%)/100)).
	 */
	protected function compute_installments( array $rates, $brand, $total ) {
		$installments = array();
		if ( empty( $rates['oranlar'][ $brand ] ) || $total <= 0 ) {
			return $installments;
		}
		foreach ( (array) $rates['oranlar'][ $brand ] as $count => $rate ) {
			$count = (int) $count;
			if ( $count < 2 ) {
				continue;
			}
			$pct       = (float) $rate;
			$grand     = Api::gross_up( $total, $pct );
			$per_month = round( $grand / $count, 2 );
			$installments[] = array(
				'count'    => $count,
				'total'    => $grand,
				'perMonth' => $per_month,
			);
		}
		usort( $installments, function ( $a, $b ) {
			return $a['count'] <=> $b['count'];
		} );
		return $installments;
	}

	/* ------------------------------------------------------------------ */
	/*  AJAX: 3D Secure HTML teslimi (tek kullanımlık)                    */
	/* ------------------------------------------------------------------ */

	public function ajax_get_3ds() {
		check_ajax_referer( 'paytr_inline', 'nonce' );

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$key      = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order || ! hash_equals( $order->get_order_key(), $key ) ) {
			wp_send_json_error( array( 'message' => __( 'Sipariş doğrulanamadı.', 'paytr-inline-checkout' ) ) );
		}

		$tkey = 'paytr_3ds_' . $order_id . '_' . $key;
		$html = get_transient( $tkey );
		if ( ! $html ) {
			wp_send_json_error( array( 'message' => __( '3D Secure oturumu süresi doldu, lütfen tekrar deneyin.', 'paytr-inline-checkout' ) ) );
		}
		delete_transient( $tkey );

		wp_send_json_success( array( 'html' => $html ) );
	}

	/* ------------------------------------------------------------------ */
	/*  AJAX: ödeme durumu sorgulama (parent pencere yönlendirme kararı)  */
	/* ------------------------------------------------------------------ */

	public function ajax_status() {
		check_ajax_referer( 'paytr_inline', 'nonce' );

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$key      = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order || ! hash_equals( $order->get_order_key(), $key ) ) {
			wp_send_json_error();
		}

		if ( $order->has_status( array( 'processing', 'completed', 'on-hold' ) ) ) {
			wp_send_json_success( array(
				'status'   => 'paid',
				'redirect' => $order->get_checkout_order_received_url(),
			) );
		}
		if ( $order->has_status( array( 'failed', 'cancelled' ) ) ) {
			wp_send_json_success( array( 'status' => 'failed' ) );
		}

		wp_send_json_success( array( 'status' => 'pending' ) );
	}

	/* ------------------------------------------------------------------ */
	/*  PayTR bildirim (webhook)                                          */
	/* ------------------------------------------------------------------ */

	public function handle_notify() {
		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$api = $this->api();

		if ( ! $api->verify_notification_hash( $post ) ) {
			status_header( 400 );
			error_log( 'PayTR Inline: geçersiz bildirim hash\'i, oid=' . ( $post['merchant_oid'] ?? '?' ) ); // phpcs:ignore
			echo 'PAYTR notification failed: bad hash';
			exit;
		}

		$order = $this->find_order_by_oid( (string) ( $post['merchant_oid'] ?? '' ) );

		if ( $order ) {
			if ( 'success' === ( $post['status'] ?? '' ) ) {
				if ( ! $order->has_status( array( 'processing', 'completed' ) ) ) {
					if ( $this->notify_amount_mismatch( $order, $post ) ) {
						// Hash geçerli (bildirim gerçekten PayTR'den) ama tutar sipariş
						// toplamıyla uyuşmuyor — otomatik tamamlamak yerine manuel
						// incelemeye düşür. Ek bir savunma katmanı; normal şartlarda
						// asla tetiklenmemesi beklenir.
						$order->update_status( 'on-hold', __( 'PayTR: bildirimdeki tutar sipariş toplamıyla uyuşmuyor, manuel inceleme gerekiyor.', 'paytr-inline-checkout' ) );
						error_log( 'PayTR Inline: tutar uyuşmazlığı, oid=' . $post['merchant_oid'] ); // phpcs:ignore
					} else {
						$order->payment_complete( sanitize_text_field( $post['merchant_oid'] ) );
						$order->add_order_note( __( 'PayTR: ödeme onaylandı (bildirim).', 'paytr-inline-checkout' ) );
					}
				}
				$order->delete_meta_data( '_paytr_inline_pending' );
				$order->save();
			} else {
				if ( ! $order->has_status( array( 'processing', 'completed' ) ) ) {
					$reason = sanitize_text_field( $post['failed_reason_msg'] ?? '' );
					$order->update_status( 'failed', sprintf( __( 'PayTR: ödeme reddedildi. %s', 'paytr-inline-checkout' ), $reason ) );
				}
			}
		} else {
			error_log( 'PayTR Inline: bildirimde eşleşen sipariş bulunamadı, oid=' . ( $post['merchant_oid'] ?? '?' ) ); // phpcs:ignore
		}

		// PayTR yalnızca düz metin "OK" bekler; başka çıktı OLMAMALI.
		echo 'OK';
		exit;
	}

	protected function find_order_by_oid( $oid ) {
		if ( ! $oid || ! preg_match( '/^WC(\d+)A/', $oid, $m ) ) {
			return null;
		}
		$order = wc_get_order( (int) $m[1] );
		return $order instanceof \WC_Order ? $order : null;
	}

	/**
	 * Bildirimdeki tutarı sipariş toplamıyla karşılaştırır (kuruş cinsinden).
	 * Hash zaten bildirimin PayTR'den geldiğini kanıtlıyor; bu sadece ek bir
	 * savunma katmanı (ör. entegrasyon hatası, tutar tutarsızlığı) — normal
	 * şartlarda hiç tetiklenmemesi beklenir.
	 *
	 * @return bool true ise tutar uyuşmuyor.
	 */
	protected function notify_amount_mismatch( \WC_Order $order, array $post ) {
		$reported = isset( $post['payment_amount'] ) ? (int) $post['payment_amount'] : (int) ( $post['total_amount'] ?? 0 );
		if ( $reported <= 0 ) {
			return false; // Bazı entegrasyon adımlarında bu alan boş gelebilir; katı reddetme.
		}
		$expected = (int) round( (float) $order->get_total() * 100 );
		return abs( $expected - $reported ) > 2; // 2 kuruşluk yuvarlama payı.
	}

	/* ------------------------------------------------------------------ */
	/*  Yardımcılar                                                       */
	/* ------------------------------------------------------------------ */

	public function hide_stub_actions( $actions, $order ) {
		if ( 'yes' === $order->get_meta( '_paytr_inline_pending' ) && $order->has_status( array( 'pending', 'failed' ) ) ) {
			unset( $actions['pay'], $actions['cancel'] );
		}
		return $actions;
	}

	public function maybe_schedule_gc() {
		if ( ! wp_next_scheduled( 'paytr_inline_gc' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'paytr_inline_gc' );
		}
	}

	public function run_gc() {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}
		$orders = wc_get_orders( array(
			'status'       => array( 'pending', 'failed' ),
			'limit'        => 40,
			'date_created' => '<' . ( time() - 2 * HOUR_IN_SECONDS ),
			'meta_key'     => '_paytr_inline_pending', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'   => 'yes',                   // phpcs:ignore WordPress.DB.SlowDBQuery
			'return'       => 'objects',
		) );
		foreach ( $orders as $o ) {
			$o->update_status( 'cancelled', __( 'Otomatik iptal: inline PayTR ödemesi tamamlanmadı.', 'paytr-inline-checkout' ) );
		}
	}
}
