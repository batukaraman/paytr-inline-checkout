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

		// order-pay sayfasında ("Öde" ile gelinen sipariş ödeme sayfası)
		// #order_review'ın gönderimini JS burada yakalar — WooCommerce'in
		// normal (tam sayfa POST/yönlendirme) order-pay akışı yerine, 3D
		// Secure'ü checkout'taki AYNI inline modal/iframe ile göstermek için.
		add_action( 'wp_ajax_paytr_inline_pay_order', array( $this, 'ajax_pay_order' ) );
		add_action( 'wp_ajax_nopriv_paytr_inline_pay_order', array( $this, 'ajax_pay_order' ) );

		// PayTR Mağaza Paneli -> Bildirim URL: /?wc-api=paytr_inline_notify
		add_action( 'woocommerce_api_paytr_inline_notify', array( $this, 'handle_notify' ) );

		// "Ödemesi bekleyen" sipariş sayfasında (order-pay) inline/iframe akışı
		// çalışmadığı için 3D Secure'ü tam sayfa olarak gösteren uç.
		add_action( 'woocommerce_api_paytr_inline_3ds_page', array( $this, 'render_3ds_fullpage' ) );

		// order-pay sayfası, PayTR'nin (harici bir alan adından) tam sayfa
		// yönlendirmesiyle geri dönüldüğünde tekrar render edilir. Bu sayfa
		// tamamen oturuma özgüdür (hesabınıza giriş yapmış olmanız gerekir);
		// bir önbellekleme eklentisi (ör. LiteSpeed Cache) bunu yanlışlıkla
		// önbelleğe alırsa, sonraki tüm ziyaretçilere (veya aynı kullanıcının
		// sonraki isteklerine) "giriş yapmanız gerekiyor" gibi eski/yanlış
		// bir anlık görüntü sunabilir. Bu yüzden bu sayfayı kesinlikle
		// önbelleklenmeyecek şekilde işaretliyoruz.
		add_action( 'template_redirect', array( $this, 'prevent_order_pay_caching' ), 0 );

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
		if ( is_wc_endpoint_url( 'order-received' ) ) {
			return;
		}

		wp_enqueue_style(
			'paytr-inline-checkout',
			PAYTR_INLINE_URL . 'assets/css/inline-checkout.css',
			array(),
			PAYTR_INLINE_VER
		);

		// order-pay sayfası ayrı bir form/AJAX ucu kullanır (#order_review,
		// checkout_order_pay). Kartı GÖNDEREN kısmı (onPlaceOrder) o formu
		// tanımadığı için hiç bağlanmıyor — WooCommerce'in kendi order-pay
		// AJAX'ı process_payment()'ı normal şekilde çağırıyor ve 3D
		// Secure'ü tam sayfa yönlendirmeyle gösteriyoruz (render_3ds_fullpage).
		// Ama kart numarası biçimlendirme, marka/BIN tespiti ve taksit
		// tablosu gibi JS'e dayalı UI'ı checkout ile BİREBİR aynı tutmak
		// için bu betiği order-pay'de de yüklüyoruz.
		$order_id_for_pay = 0;
		if ( is_wc_endpoint_url( 'order-pay' ) ) {
			$order_id_for_pay = absint( get_query_var( 'order-pay' ) );
		}

		wp_enqueue_script(
			'paytr-inline-checkout',
			PAYTR_INLINE_URL . 'assets/js/inline-checkout.js',
			array( 'jquery', 'wc-checkout' ),
			PAYTR_INLINE_VER,
			true
		);

		$order_for_pay = $order_id_for_pay ? wc_get_order( $order_id_for_pay ) : false;

		wp_localize_script( 'paytr-inline-checkout', 'paytrInline', array(
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'checkoutUrl' => class_exists( 'WC_AJAX' ) ? \WC_AJAX::get_endpoint( 'checkout' ) : wc_get_checkout_url(),
			'nonce'       => wp_create_nonce( 'paytr_inline' ),
			'method'      => Gateway::ID,
			// order-pay sayfasında sepet yok; BIN/taksit sorgularının
			// doğru tutarı hesaplayabilmesi için sipariş kimliğini yolluyoruz.
			'orderId'     => $order_for_pay ? $order_for_pay->get_id() : 0,
			'orderKey'    => $order_for_pay ? $order_for_pay->get_order_key() : '',
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

	/**
	 * order-pay sayfasında sepet boştur (sipariş zaten oluşturulmuş) — bu
	 * yüzden JS, taksit önizlemesinin doğru tutarı hesaplayabilmesi için
	 * order_id/order_key'i de AJAX isteğine ekliyor. Geçerliyse sipariş
	 * toplamını, yoksa sepet toplamını kullanıyoruz.
	 */
	protected function cart_total() {
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$key      = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( $order_id && $key ) {
			$order = wc_get_order( $order_id );
			if ( $order && hash_equals( $order->get_order_key(), $key ) ) {
				return (float) $order->get_total();
			}
		}
		return ! empty( WC()->cart ) ? (float) WC()->cart->get_total( 'edit' ) : 0;
	}

	/**
	 * Basit sabit-pencere hız sınırlama. Varsayılanlar (20 istek / 10 saniye,
	 * IP başına) BIN/taksit sorgulama uçları için: PayTR'nin kendi
	 * servislerini çağırdıklarından (önbelleklenmemiş ilk istekler) kötüye
	 * kullanımla (BIN enumeration, servis şişirme) PayTR'ye yük
	 * bindirilmesini engeller. Gerçek kart tahsilat denemesi yapan
	 * ajax_pay_order() daha sıkı özel limitlerle ayrıca çağırır (bkz. orada).
	 *
	 * @param string      $bucket
	 * @param int         $limit
	 * @param int         $window Saniye.
	 * @param string|null $identifier Varsayılan: istemci IP'si.
	 * @return bool true ise istek reddedilmeli.
	 */
	protected function rate_limited( $bucket, $limit = 20, $window = 10, $identifier = null ) {
		if ( null === $identifier ) {
			$identifier = \WC_Geolocation::get_ip_address();
		}
		$key = 'paytr_inline_rl_' . $bucket . '_' . md5( $identifier );
		$hit = (int) get_transient( $key );
		if ( $hit >= $limit ) {
			return true;
		}
		set_transient( $key, $hit + 1, $window );
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
	/*  AJAX: order-pay ("Öde") sayfasının gönderimi                      */
	/* ------------------------------------------------------------------ */

	/**
	 * WooCommerce'in normal order-pay akışı (WC_Form_Handler::pay_action())
	 * tam sayfa bir POST + wp_redirect() ile çalışır. Bunun yerine bu uç,
	 * AYNI doğrulamaları yapıp process_payment()'ı çağırır ve sonucu JSON
	 * olarak döner — böylece order-pay sayfası da checkout ile aynı inline
	 * 3D Secure modal/iframe akışını kullanabilir.
	 *
	 * Neden: tam sayfa yönlendirmede PayTR'nin geri dönüşü bazen çapraz-site
	 * bir POST ile oluyor; tarayıcı (SameSite=Lax) o istekte oturum çerezini
	 * göndermeyebiliyor, bu da order-pay'in "giriş yapmanız gerekiyor"
	 * uyarısını yanlışlıkla göstermesine yol açıyordu. İnline iframe akışında
	 * bu son adım PayTR'nin kendi ACS/iframe'i içinde kalır; üst sayfa (ve
	 * onun geçerli oturumu) hiç yeniden istenmez.
	 *
	 * @see \WC_Form_Handler::pay_action()
	 */
	public function ajax_pay_order() {
		check_ajax_referer( 'paytr_inline', 'nonce' );

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;

		// Kart tahsilat denemesi yapan tek uç burası — kart testi/carding
		// saldırılarına karşı iki ayrı sınır uyguluyoruz: tek bir IP'nin
		// FARKLI siparişlerde art arda kart denemesi ("bin/all_rates" ile
		// aynı IP başına genel limit) ve tek bir siparişin (ör. çalınmış bir
		// order_key ile, farklı IP'lerden bile olsa) art arda denenmesi.
		if ( $this->rate_limited( 'pay_ip', 10, 60 ) ) {
			wp_send_json( $this->pay_error( __( 'Çok fazla ödeme denemesi yapıldı. Lütfen birkaç dakika sonra tekrar deneyin.', 'paytr-inline-checkout' ) ) );
		}
		if ( $order_id && $this->rate_limited( 'pay_order', 5, 60, 'order_' . $order_id ) ) {
			wp_send_json( $this->pay_error( __( 'Çok fazla ödeme denemesi yapıldı. Lütfen birkaç dakika sonra tekrar deneyin.', 'paytr-inline-checkout' ) ) );
		}

		$key   = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
		$order = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order || ! hash_equals( $order->get_order_key(), $key ) ) {
			wp_send_json( $this->pay_error( __( 'Sipariş doğrulanamadı.', 'paytr-inline-checkout' ) ) );
		}

		if ( ! current_user_can( 'pay_for_order', $order_id ) ) {
			wp_send_json( $this->pay_error( __( 'Ödeme formuna devam etmek için lütfen hesabınıza giriş yapınız.', 'paytr-inline-checkout' ) ) );
		}

		if ( ! $order->needs_payment() ) {
			wp_send_json( array(
				'result'   => 'success',
				'redirect' => $order->get_checkout_order_received_url(),
			) );
		}

		// Bu noktadan sonrası WC_Form_Handler::pay_action() ile aynı
		// davranışı taklit eder (bkz. o metodun kaynak kodu): sipariş+anahtar
		// doğrulanıp ödeme gerektiği kesinleştiğinde 'woocommerce_before_pay_action'
		// tetiklenir — WooCommerce Subscriptions/Deposits gibi bazı eklentiler
		// order-pay akışının çalıştığını bu hook'tan anlar. Bunu atlarsak
		// order-pay'i AJAX'a taşımamız o eklentilerle sessizce uyumsuz kalırdı.
		do_action( 'woocommerce_before_pay_action', $order );

		WC()->customer->set_props( array(
			'billing_country'  => $order->get_billing_country() ? $order->get_billing_country() : null,
			'billing_state'    => $order->get_billing_state() ? $order->get_billing_state() : null,
			'billing_postcode' => $order->get_billing_postcode() ? $order->get_billing_postcode() : null,
			'billing_city'     => $order->get_billing_city() ? $order->get_billing_city() : null,
		) );
		WC()->customer->save();

		// checkout/terms.php şartlar/koşullar kutusunu zorunlu kılıyorsa
		// (mağaza ayarına bağlı) çekirdek pay_action() de aynı kontrolü
		// yapar — bu uç atlarsa order-pay üzerinden şartlar onayı hiç
		// zorunlu olmazdı.
		if ( ! empty( $_POST['terms-field'] ) && empty( $_POST['terms'] ) ) {
			wp_send_json( $this->pay_error( __( 'Sipariş ile devam etmek için lütfen şartları ve koşulları okuyup kabul edin.', 'paytr-inline-checkout' ) ) );
		}

		$payment_method_id  = isset( $_POST['payment_method'] ) ? wc_clean( wp_unslash( $_POST['payment_method'] ) ) : '';
		$available_gateways = WC()->payment_gateways()->get_available_payment_gateways();
		$gateway            = isset( $available_gateways[ $payment_method_id ] ) ? $available_gateways[ $payment_method_id ] : null;

		if ( ! $gateway ) {
			do_action( 'woocommerce_after_pay_action', $order );
			wp_send_json( $this->pay_error( __( 'Geçersiz ödeme yöntemi.', 'paytr-inline-checkout' ) ) );
		}

		$order->set_payment_method( $gateway );
		$order->save();

		$gateway->validate_fields();

		if ( wc_notice_count( 'error' ) > 0 ) {
			do_action( 'woocommerce_after_pay_action', $order );
			wp_send_json( array(
				'result'   => 'failure',
				'messages' => wc_print_notices( true ),
			) );
		}

		$result = $gateway->process_payment( $order_id );

		if ( ! isset( $result['result'] ) || 'success' !== $result['result'] ) {
			do_action( 'woocommerce_after_pay_action', $order );
			wp_send_json( array(
				'result'   => 'failure',
				'messages' => wc_print_notices( true ),
			) );
		}

		// 'woocommerce_after_pay_action' başarılı sonuçta ÇALIŞTIRILMAZ —
		// çekirdeğin kendi pay_action()'ı da başarı durumunda hemen
		// wp_redirect()+exit ile döndüğü için bu hook'a hiç ulaşmaz; burada
		// da aynı sıra korunuyor (bkz. yukarıdaki yorum).
		$result['order_id'] = $order_id;

		wp_send_json( apply_filters( 'woocommerce_payment_successful_result', $result, $order_id ) );
	}

	/**
	 * order-pay AJAX'ının tekrar eden "woocommerce-error" sarmalayıcısı.
	 *
	 * @param string $message
	 * @return array
	 */
	protected function pay_error( $message ) {
		return array(
			'result'   => 'failure',
			'messages' => '<ul class="woocommerce-error"><li>' . esc_html( $message ) . '</li></ul>',
		);
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

		$oid    = (string) ( $post['merchant_oid'] ?? '' );
		$order  = $this->find_order_by_oid( $oid );
		$status = (string) ( $post['status'] ?? '' );

		if ( $order ) {
			$is_stale = $this->is_stale_notification( $order, $oid );

			if ( 'success' === $status ) {
				if ( ! $order->has_status( array( 'processing', 'completed' ) ) ) {
					if ( $this->notify_amount_mismatch( $order, $post ) ) {
						// Hash geçerli (bildirim gerçekten PayTR'den) ama tutar sipariş
						// toplamıyla uyuşmuyor — otomatik tamamlamak yerine manuel
						// incelemeye düşür. Ek bir savunma katmanı; normal şartlarda
						// asla tetiklenmemesi beklenir.
						$order->update_status( 'on-hold', __( 'PayTR: bildirimdeki tutar sipariş toplamıyla uyuşmuyor, manuel inceleme gerekiyor.', 'paytr-inline-checkout' ) );
						error_log( 'PayTR Inline: tutar uyuşmazlığı, oid=' . $post['merchant_oid'] ); // phpcs:ignore
					} else {
						// ÖNEMLİ: "eski" (güncel olmayan) bir denemeye ait olsa
						// BİLE bir "success" bildirimi asla sessizce atılmaz.
						// Hash geçerli demek PayTR'de gerçekten tahsilat yapıldığı
						// anlamına gelir; müşteri bu denemeden SONRA yeni bir
						// deneme başlatmış olsa dahi parayı görmezden gelip
						// siparişi ödenmemiş bırakmak ("tahsil edildi ama sipariş
						// hiç tamamlanmadı" durumu) burada atlanan riskten çok
						// daha kötüdür. Bunun yerine ödemeyi işleyip, olası çift
						// tahsilat ihtimaline karşı manuel incelemeye işaret
						// düşüyoruz.
						$order->payment_complete( sanitize_text_field( $post['merchant_oid'] ) );
						if ( $is_stale ) {
							$order->add_order_note( __( 'PayTR: ödeme onaylandı (bildirim). UYARI: bu bildirim, müşterinin bu siparişte SONRADAN yeni bir deneme başlatmasından SONRA geldi (eski deneme). Olası çift tahsilat ihtimaline karşı PayTR Mağaza Panelinden bu siparişin tüm denemelerini kontrol edin.', 'paytr-inline-checkout' ) );
							error_log( 'PayTR Inline: eski bir denemenin GEÇ gelen BAŞARI bildirimi işlendi (çift tahsilat ihtimali kontrol edilmeli), oid=' . $oid ); // phpcs:ignore
						} else {
							$order->add_order_note( __( 'PayTR: ödeme onaylandı (bildirim).', 'paytr-inline-checkout' ) );
						}
					}
				}
				$order->delete_meta_data( '_paytr_inline_pending' );
				$order->save();
			} elseif ( $is_stale ) {
				// Müşteri bir siparişi birden fazla kez denediğinde, ÖNCEKİ
				// (iptal edilmiş/reddedilmiş) bir denemeye ait geç gelen bir
				// RET/hata bildirimi, o sırada devam eden YENİ bir denemeyi
				// yanlışlıkla "failed"e düşürebilir. Bu yalnızca başarısız
				// bildirimler için güvenli şekilde yok sayılabilir — bir
				// "success" bildirimi için bu dal hiç çalışmaz (yukarıda ele
				// alınır), yani gerçek bir tahsilat asla bu şekilde atılmaz.
				error_log( 'PayTR Inline: eski (güncel olmayan) RET bildirimi yok sayıldı, oid=' . $oid ); // phpcs:ignore
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

	/**
	 * Bildirimdeki merchant_oid, siparişin EN SON ürettiği denemeyle
	 * (Api::merchant_oid içinde kaydedilen _paytr_last_oid) eşleşmiyorsa bu
	 * bildirim eskidir — müşteri aynı siparişi birden fazla kez denediğinde
	 * (ör. iptal edip tekrar denediğinde) önceki denemeye ait geç gelen bir
	 * sonucun güncel denemeyi ezmesini engeller. _paytr_last_oid meta'sı
	 * yoksa (eski sipariş/geriye dönük uyumluluk) katı reddetme yapılmaz.
	 *
	 * @return bool true ise bildirim eski/güncel olmayan bir denemeye ait.
	 */
	protected function is_stale_notification( \WC_Order $order, $oid ) {
		$last = (string) $order->get_meta( '_paytr_last_oid' );
		return $last && $oid && $last !== $oid;
	}

	/* ------------------------------------------------------------------ */
	/*  Yardımcılar                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * order-pay, kullanıcıya özel ("bu sipariş SİZE mi ait, giriş yapmış
	 * mısınız") bir kontrolle render edilir. PayTR'den (harici bir alan
	 * adından) tam sayfa yönlendirmeyle buraya dönüldüğünde bir önbellekleme
	 * eklentisi bu sayfayı yanlışlıkla önbelleğe alırsa, sonraki istekler
	 * (aynı kullanıcının sayfayı yenilemesi dahil) eski/yanlış bir anlık
	 * görüntüyü (ör. "giriş yapmanız gerekiyor" uyarısını) görebilir. Bu
	 * yüzden order-pay'i kesinlikle önbelleklenmeyecek şekilde işaretliyoruz.
	 */
	public function prevent_order_pay_caching() {
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
			$this->send_no_cache_headers();
		}
	}

	protected function send_no_cache_headers() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}
		if ( ! headers_sent() ) {
			// LiteSpeed Cache (ve genel olarak ters proxy tabanlı önbellekler)
			// için özel işaret — bazı sürümler yalnızca standart
			// Cache-Control: no-store başlığına güvenmeyebiliyor.
			header( 'X-LiteSpeed-Cache-Control: no-cache' );
		}
	}

	/**
	 * "Ödemesi bekleyen"/"Başarısız" bir siparişte müşteri "Öde"ye tıklayınca
	 * gelinen order-pay sayfasında process_payment() tam sayfa 3D Secure
	 * yönlendirmesi üretir (bkz. Gateway::process_payment). Bu uç, o
	 * yönlendirmenin hedefidir: PayTR'nin ürettiği 3D Secure HTML'ini WP
	 * şablonuna sarmadan olduğu gibi basar; merchant_ok_url/fail_url zaten
	 * tam sayfa yönlendirmeyle akışı doğal olarak tamamlar.
	 */
	public function render_3ds_fullpage() {
		$this->send_no_cache_headers();

		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order || ! hash_equals( $order->get_order_key(), $key ) ) {
			wp_die( esc_html__( 'Sipariş doğrulanamadı.', 'paytr-inline-checkout' ) );
		}

		$tkey = 'paytr_3ds_' . $order_id . '_' . $key;
		$html = get_transient( $tkey );
		if ( ! $html ) {
			wp_safe_redirect( $order->get_checkout_payment_url() );
			exit;
		}
		delete_transient( $tkey );

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
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
