<?php
/**
 * "paytr_inline" ödeme yöntemi.
 *
 * Kart alanları (isim, numara, SKT, CVC) checkout akordeonunda doğrudan bu
 * eklenti tarafından render edilir (resmi PayTR eklentisi yok). Form
 * gönderildiğinde process_payment() PayTR Direkt API'ye kart verisini iletir
 * ve dönen 3D Secure sayfasını inline bir iframe'de göstermek üzere bir
 * "sentinel" sonuç döner — gerçek tamamlama PayTR'nin bildirim (webhook)
 * isteği ile olur (bkz. Controller::handle_notify).
 */

namespace PaytrInlineCheckout;

defined( 'ABSPATH' ) || exit;

class Gateway extends \WC_Payment_Gateway {

	const ID = 'paytr_inline';

	public function __construct() {
		$this->id                 = self::ID;
		$this->method_title       = __( 'PayTR — Kart ile Öde (Inline)', 'paytr-inline-checkout' );
		$this->method_description = __( 'PayTR Direkt API ile kart formunu checkout sayfasının içinde gösterir. Kart verisi veritabanına kaydedilmez.', 'paytr-inline-checkout' );
		$this->has_fields         = true;
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title             = $this->get_option( 'title' );
		$this->description       = $this->get_option( 'description' );
		$this->icon              = PAYTR_INLINE_URL . 'assets/images/paytr-logo-single.svg';
		$this->order_button_text = $this->get_option( 'button_text' ) ?: __( 'Siparişi Ver', 'paytr-inline-checkout' );
		$this->enabled            = $this->get_option( 'enabled', 'yes' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'       => array(
				'title'   => __( 'Etkin / Pasif', 'paytr-inline-checkout' ),
				'label'   => __( 'PayTR kart formunu ödeme sayfasının içinde (inline) göster', 'paytr-inline-checkout' ),
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			'title'         => array(
				'title'       => __( 'Başlık', 'paytr-inline-checkout' ),
				'type'        => 'text',
				'default'     => __( 'Kredi/Banka Kartı ile Öde (PayTR)', 'paytr-inline-checkout' ),
				'desc_tip'    => true,
			),
			'description'   => array(
				'title'       => __( 'Açıklama', 'paytr-inline-checkout' ),
				'type'        => 'textarea',
				'default'     => __( 'Kart bilgileriniz 3D Secure ile güvenle işlenir.', 'paytr-inline-checkout' ),
			),
			'button_text'   => array(
				'title'       => __( 'Sipariş Butonu Metni', 'paytr-inline-checkout' ),
				'type'        => 'text',
				'default'     => __( 'Siparişi Ver', 'paytr-inline-checkout' ),
			),
			'api_settings'  => array(
				'title' => __( 'PayTR Mağaza Bilgileri', 'paytr-inline-checkout' ),
				'type'  => 'title',
				'description' => __( 'PayTR Mağaza Panel &rarr; Ayarlar &rarr; Bilgi Al ekranından alınır.', 'paytr-inline-checkout' ),
			),
			'merchant_id'   => array(
				'title'       => __( 'Mağaza Numarası (merchant_id)', 'paytr-inline-checkout' ),
				'type'        => 'text',
			),
			'merchant_key'  => array(
				'title'       => __( 'Mağaza Parolası (merchant_key)', 'paytr-inline-checkout' ),
				'type'        => 'password',
			),
			'merchant_salt' => array(
				'title'       => __( 'Mağaza Gizli Anahtarı (merchant_salt)', 'paytr-inline-checkout' ),
				'type'        => 'password',
			),
			'test_mode'     => array(
				'title'       => __( 'Test Modu', 'paytr-inline-checkout' ),
				'label'       => __( 'PayTR isteklerini test modunda gönder', 'paytr-inline-checkout' ),
				'type'        => 'checkbox',
				'default'     => 'no',
			),
			'debug_on'      => array(
				'title'       => __( 'Hata Ayıklama', 'paytr-inline-checkout' ),
				'label'       => __( 'PayTR hata mesajlarını detaylı döndür (debug_on)', 'paytr-inline-checkout' ),
				'type'        => 'checkbox',
				'default'     => 'no',
			),
			'notify_info'   => array(
				'type'        => 'title',
				'title'       => __( 'Bildirim (Webhook) URL', 'paytr-inline-checkout' ),
				/* translators: %s: notify URL */
				'description' => sprintf(
					__( 'PayTR Mağaza Paneli &rarr; Bildirim URL alanına şunu girin: %s', 'paytr-inline-checkout' ),
					'<code>' . esc_html( home_url( '/?wc-api=paytr_inline_notify' ) ) . '</code>'
				),
			),
		);
	}

	public function api_settings() {
		return array(
			'merchant_id'   => $this->get_option( 'merchant_id' ),
			'merchant_key'  => $this->get_option( 'merchant_key' ),
			'merchant_salt' => $this->get_option( 'merchant_salt' ),
			'test_mode'     => $this->get_option( 'test_mode' ),
			'debug_on'      => $this->get_option( 'debug_on' ),
		);
	}

	public function is_available() {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return parent::is_available();
		}
		if ( 'yes' !== $this->enabled ) {
			return false;
		}
		$s = $this->api_settings();
		if ( empty( $s['merchant_id'] ) || empty( $s['merchant_key'] ) || empty( $s['merchant_salt'] ) ) {
			return false;
		}
		return parent::is_available();
	}

	/**
	 * WooCommerce > Ayarlar > Ödemeler listesinde PayTR logosu, checkout
	 * akordeonunda başlığın yanında ise kabul edilen kart logoları gösterilir.
	 */
	public function get_icon() {
		$icon_url = ( is_admin() && ! wp_doing_ajax() )
			? PAYTR_INLINE_URL . 'assets/images/paytr-logo-single.svg'
			: PAYTR_INLINE_URL . 'assets/images/payment-cards.png';

		$icon = '<img src="' . esc_url( $icon_url ) . '" alt="' . esc_attr( $this->get_title() ) . '" />';

		return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id );
	}

	/**
	 * Akordeon içeriği: kart alanları + taksit tablosu mount noktası.
	 * Gerçek DOM/CSS/JS assets/js/inline-checkout.js tarafından yönetilir.
	 */
	public function payment_fields() {
		if ( $this->description ) {
			echo wpautop( wptexturize( wp_kses_post( $this->description ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		$s         = $this->api_settings();
		$test_mode = ! empty( $s['test_mode'] ) && 'yes' === $s['test_mode'];

		$kvkk_page = get_page_by_path( 'kvkk-politikasi' );
		$kvkk_url  = $kvkk_page ? get_permalink( $kvkk_page ) : home_url( '/kvkk-politikasi/' );

		// Bazı hesaplarda (ör. BDDK taksit yasası kapsamındaki sektörler) PayTR
		// taksit oranı servisini başarıyla ama tamamen boş döner. Bu durumda
		// "Tüm Taksit Seçeneklerini Göster" bağlantısını hiç göstermiyoruz —
		// tıklanınca yanlışlıkla hata gibi görünen boş bir sonuç vermesindense.
		$has_installments = false;
		$rates            = ( new Api( $s ) )->installment_rates();
		if ( ! is_wp_error( $rates ) && ! empty( $rates['oranlar'] ) ) {
			foreach ( (array) $rates['oranlar'] as $brand_rates ) {
				foreach ( (array) $brand_rates as $count => $rate ) {
					if ( (int) $count >= 2 && (float) $rate > 0 ) {
						$has_installments = true;
						break 2;
					}
				}
			}
		}
		?>
		<div id="paytr-inline-mount" class="paytr-inline-mount" data-state="idle">
			<?php if ( $test_mode ) : ?>
				<div class="paytr-inline-sandbox"><?php esc_html_e( 'Test Modu', 'paytr-inline-checkout' ); ?></div>
			<?php endif; ?>

			<div class="paytr-inline-card">
				<div class="paytr-inline-field paytr-inline-field--owner">
					<?php echo $this->icon( 'user' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<input type="text" id="paytr_cc_owner" name="paytr_cc_owner" autocomplete="cc-name"
						aria-label="<?php esc_attr_e( 'Kart Üzerindeki İsim', 'paytr-inline-checkout' ); ?>"
						placeholder="<?php esc_attr_e( 'Kart Üzerindeki Ad Soyad', 'paytr-inline-checkout' ); ?>" />
				</div>
				<div class="paytr-inline-field paytr-inline-field--number">
					<?php echo $this->icon( 'card' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<input type="text" id="paytr_card_number" name="paytr_card_number" inputmode="numeric" autocomplete="cc-number" maxlength="23"
						aria-label="<?php esc_attr_e( 'Kart Numarası', 'paytr-inline-checkout' ); ?>"
						placeholder="<?php esc_attr_e( 'Kart Numarası', 'paytr-inline-checkout' ); ?>" />
					<span class="paytr-inline-card-brand" aria-hidden="true"></span>
				</div>
				<div class="paytr-inline-row">
					<div class="paytr-inline-field paytr-inline-field--expiry">
						<?php echo $this->icon( 'calendar' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<input type="text" id="paytr_expiry" inputmode="numeric" autocomplete="cc-exp" maxlength="5"
							aria-label="<?php esc_attr_e( 'Son Kullanma Tarihi', 'paytr-inline-checkout' ); ?>"
							placeholder="<?php esc_attr_e( 'Ay / Yıl', 'paytr-inline-checkout' ); ?>" />
						<input type="hidden" id="paytr_expiry_month" name="paytr_expiry_month" />
						<input type="hidden" id="paytr_expiry_year" name="paytr_expiry_year" />
						<input type="hidden" id="paytr_card_brand" name="paytr_card_brand" />
					</div>
					<div class="paytr-inline-field paytr-inline-field--cvc">
						<?php echo $this->icon( 'lock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<input type="text" id="paytr_cvv" name="paytr_cvv" inputmode="numeric" autocomplete="cc-csc" maxlength="4"
							aria-label="CVC"
							placeholder="CVC" />
						<?php echo $this->icon( 'cvc-card' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
				</div>
			</div>

			<p class="paytr-inline-installments-heading" id="paytr-inline-installments-heading" hidden><?php esc_html_e( 'Taksit Seçenekleri', 'paytr-inline-checkout' ); ?></p>
			<div class="paytr-inline-installments" id="paytr-inline-installments">
				<p class="paytr-inline-installments-placeholder" id="paytr-inline-installments-placeholder">
					<?php esc_html_e( 'Taksit seçenekleri kart numaranızı girdikten sonra görünecektir.', 'paytr-inline-checkout' ); ?>
				</p>
			</div>

			<div class="paytr-inline-3dsrow">
				<span class="paytr-inline-3dsrow-box" aria-hidden="true"></span>
				<span class="paytr-inline-3dsrow-label"><?php esc_html_e( '3D Secure', 'paytr-inline-checkout' ); ?></span>
				<span class="paytr-inline-3dsrow-info" tabindex="0" title="<?php esc_attr_e( 'Bu ödeme her zaman 3D Secure ile, kart bilgileriniz sunucumuzda saklanmadan doğrudan PayTR üzerinden işlenir. Bu bir seçenek değil, her ödemede zorunlu olarak uygulanır.', 'paytr-inline-checkout' ); ?>">i</span>
			</div>

			<div class="paytr-inline-trustrow">
				<span class="paytr-inline-trustrow-icon" aria-hidden="true"><?php echo $this->icon( 'shield' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<span>
					<img class="paytr-inline-trustrow-brand" src="<?php echo esc_url( PAYTR_INLINE_URL . 'assets/images/paytr-logo.svg' ); ?>" alt="PayTR" />
					<?php esc_html_e( 'güvencesi ile korumalı ödeme.', 'paytr-inline-checkout' ); ?>
				</span>
			</div>

			<p class="paytr-inline-consent">
				<?php
				printf(
					/* translators: %s: KVKK page link */
					esc_html__( 'Ödeme işlemine devam ederek %s\'ni okuduğumu ve anladığımı kabul ediyorum.', 'paytr-inline-checkout' ),
					'<a href="' . esc_url( $kvkk_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'KVKK Aydınlatma Metni', 'paytr-inline-checkout' ) . '</a>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				);
				?>
			</p>

			<?php if ( $has_installments ) : ?>
				<button type="button" class="paytr-inline-showall" id="paytr-inline-showall">
					<?php esc_html_e( 'Tüm Taksit Seçeneklerini Göster', 'paytr-inline-checkout' ); ?>
				</button>
				<div class="paytr-inline-allrates" id="paytr-inline-allrates" hidden></div>
			<?php endif; ?>

			<div class="paytr-inline-3ds-overlay" id="paytr-inline-3ds-overlay" hidden>
				<div class="paytr-inline-3ds-box">
					<button type="button" class="paytr-inline-3ds-close" aria-label="<?php esc_attr_e( 'Kapat', 'paytr-inline-checkout' ); ?>">&times;</button>
					<iframe id="paytr-inline-3ds-frame" title="3D Secure"></iframe>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * iyzico inline eklentisinin görsel diliyle birebir uyumlu, bağımsız
	 * (kopyalanmış marka varlığı olmayan) minik çizgi ikonlar.
	 */
	protected function icon( $name ) {
		$paths = array(
			'user'     => '<circle cx="12" cy="8" r="3.2"/><path d="M5 20c1.4-3.6 4.2-5.4 7-5.4s5.6 1.8 7 5.4"/>',
			'card'     => '<rect x="3.5" y="6" width="17" height="12" rx="2"/><path d="M3.5 10.2h17"/>',
			'calendar' => '<rect x="4" y="5.5" width="16" height="14" rx="2"/><path d="M4 10h16M8 3.5v3M16 3.5v3"/>',
			'lock'     => '<rect x="5.5" y="10.5" width="13" height="9" rx="2"/><path d="M8.5 10.5V8a3.5 3.5 0 0 1 7 0v2.5"/>',
			'check'    => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
			'shield'   => '<path d="M12 3.5l7 2.5v5.2c0 4.4-2.9 7.9-7 9.3-4.1-1.4-7-4.9-7-9.3V6l7-2.5z"/><path d="M8.7 12.2l2.3 2.3 4.3-4.7"/>',
			'cvc-card' => '<rect x="2.5" y="5" width="19" height="14" rx="2.2"/><path d="M2.5 9.2h19"/><rect x="14.5" y="12.6" width="4.3" height="2.8" rx="0.6" fill="currentColor" stroke="none"/>',
		);
		if ( empty( $paths[ $name ] ) ) {
			return '';
		}
		return '<svg class="paytr-inline-icon paytr-inline-icon--' . esc_attr( $name ) . '" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths[ $name ] . '</svg>';
	}

	/**
	 * Kart doğrulaması PayTR'nin kendi yanıtında (3D/ret) yapılır; burada
	 * yalnızca alanların dolu olup olmadığına bakılır ki boş kart isteği
	 * PayTR'ye hiç gitmesin.
	 */
	public function validate_fields() {
		$owner = isset( $_POST['paytr_cc_owner'] ) ? sanitize_text_field( wp_unslash( $_POST['paytr_cc_owner'] ) ) : '';
		$num   = isset( $_POST['paytr_card_number'] ) ? preg_replace( '/\D/', '', wp_unslash( $_POST['paytr_card_number'] ) ) : '';
		$month = isset( $_POST['paytr_expiry_month'] ) ? preg_replace( '/\D/', '', wp_unslash( $_POST['paytr_expiry_month'] ) ) : '';
		$year  = isset( $_POST['paytr_expiry_year'] ) ? preg_replace( '/\D/', '', wp_unslash( $_POST['paytr_expiry_year'] ) ) : '';
		$cvv   = isset( $_POST['paytr_cvv'] ) ? preg_replace( '/\D/', '', wp_unslash( $_POST['paytr_cvv'] ) ) : '';

		if ( ! $owner || strlen( $num ) < 15 || strlen( $num ) > 19 || ! $month || strlen( $year ) !== 2 || strlen( $cvv ) < 3 ) {
			wc_add_notice( __( 'Lütfen kart bilgilerinizi eksiksiz ve doğru girin.', 'paytr-inline-checkout' ), 'error' );
			return false;
		}
		return true;
	}

	/**
	 * Sipariş oluşturulduktan sonra PayTR'ye kart isteğini gönderir.
	 * Kart alanları $_POST'tan okunur, tek bir Api::charge() çağrısı için
	 * kullanılır ve hiçbir yerde kalıcı olarak saklanmaz.
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( __( 'Sipariş bulunamadı.', 'paytr-inline-checkout' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$card = array(
			'owner' => isset( $_POST['paytr_cc_owner'] ) ? sanitize_text_field( wp_unslash( $_POST['paytr_cc_owner'] ) ) : '',
			'number'=> isset( $_POST['paytr_card_number'] ) ? wp_unslash( $_POST['paytr_card_number'] ) : '',
			'month' => isset( $_POST['paytr_expiry_month'] ) ? wp_unslash( $_POST['paytr_expiry_month'] ) : '',
			'year'  => isset( $_POST['paytr_expiry_year'] ) ? wp_unslash( $_POST['paytr_expiry_year'] ) : '',
			'cvv'   => isset( $_POST['paytr_cvv'] ) ? wp_unslash( $_POST['paytr_cvv'] ) : '',
			'brand' => isset( $_POST['paytr_card_brand'] ) ? sanitize_key( wp_unslash( $_POST['paytr_card_brand'] ) ) : '',
		);
		$installment = isset( $_POST['paytr_installment'] ) ? (int) $_POST['paytr_installment'] : 0;

		$last4 = substr( preg_replace( '/\D/', '', (string) $card['number'] ), -4 );

		$api = new Api( $this->api_settings() );

		// PayTR Direkt API'de komisyonu PayTR eklemez; "peşin fiyatına taksit"
		// mantığıyla vade farkını tutara EKLEYİP göndermek üye işyerinin
		// sorumluluğudur (bkz. PayTR desteğinin doğruladığı formül). Seçilen
		// taksit sayısı için vade farkını hesaplayıp siparişe ücret olarak
		// ekliyoruz ki hem müşteri gerçek tutarı görsün hem de PayTR'ye
		// gönderilen tutar (order->get_total()) doğru olsun.
		if ( $installment >= 2 && $card['brand'] ) {
			$rates = $api->installment_rates();
			$pct   = ! is_wp_error( $rates ) ? (float) ( $rates['oranlar'][ $card['brand'] ][ $installment ] ?? 0 ) : 0;
			if ( $pct > 0 ) {
				$base  = (float) $order->get_total();
				$gross = Api::gross_up( $base, $pct );
				$diff  = round( $gross - $base, 2 );
				if ( $diff > 0 ) {
					$fee = new \WC_Order_Item_Fee();
					/* translators: %d: taksit sayısı */
					$fee->set_name( sprintf( __( 'Taksit vade farkı (%d taksit)', 'paytr-inline-checkout' ), $installment ) );
					$fee->set_amount( $diff );
					$fee->set_total( $diff );
					$order->add_item( $fee );
					$order->calculate_totals( false );
					$order->save();
				}
			}
		}

		$ok_url   = $order->get_checkout_order_received_url();
		$fail_url = add_query_arg( array( 'paytr_failed' => $order_id ), wc_get_checkout_url() );

		$result = $api->charge( $order, $card, $installment, \WC_Geolocation::get_ip_address(), $ok_url, $fail_url );

		// Kart dizisini hemen serbest bırak.
		$card = null;

		if ( is_wp_error( $result ) ) {
			wc_add_notice( $result->get_error_message(), 'error' );
			$order->add_order_note( sprintf( __( 'PayTR isteği başarısız: %s', 'paytr-inline-checkout' ), $result->get_error_message() ) );
			return array( 'result' => 'failure' );
		}

		$order->update_meta_data( '_paytr_inline_pending', 'yes' );
		if ( $last4 ) {
			$order->add_order_note( sprintf( __( 'PayTR 3D Secure başlatıldı (kart **** %s, %d taksit).', 'paytr-inline-checkout' ), $last4, max( 1, $installment ) ) );
		}
		$order->save();

		set_transient( 'paytr_3ds_' . $order_id . '_' . $order->get_order_key(), $result['html'], 15 * MINUTE_IN_SECONDS );

		return array(
			'result'   => 'success',
			// JS sinyali — gerçek yönlendirme yok. order id/key JS'in ikinci
			// AJAX çağrısı (3DS HTML'ini çekmek) için buraya kodlanıyor.
			'redirect' => '#paytr-inline-3ds|' . $order_id . '|' . $order->get_order_key(),
		);
	}
}
