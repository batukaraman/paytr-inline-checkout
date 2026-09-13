<?php
/**
 * Plugin Name:  PayTR Inline Checkout for WooCommerce
 * Description:  PayTR Direkt API (Direct API) ile kart formunu checkout sayfasının içinde (akordeon) render eder; kart verisi WordPress veritabanına yazılmaz, doğrudan PayTR'ye iletilir. Resmi "PayTR" eklentisine bağımlı DEĞİLDİR.
 * Version:      1.0.0
 * Author:       Batuhan Karaman
 * Text Domain:  paytr-inline-checkout
 * Requires Plugins: woocommerce
 *
 * Mimari not:
 *  - Bu eklenti resmi PayTR WooCommerce eklentisini kullanmaz / gerektirmez.
 *    Tüm Direkt API (token/hash üretimi, ödeme isteği, 3D Secure yönlendirmesi,
 *    BIN/taksit sorgulama, bildirim/callback doğrulaması) bu eklenti içinde
 *    sıfırdan uygulanmıştır (bkz. dev.paytr.com "Direkt API" dokümanı).
 *  - Kart numarası / SKT / CVC hiçbir zaman veritabanına, order meta'sına ya da
 *    log dosyasına yazılmaz; yalnızca istek anında PayTR'ye iletilip anında
 *    bellekten atılır (bkz. includes/class-paytr-api.php).
 *  - Bu eklenti "iyzico Inline Checkout" eklentisinin dosyalarına dokunmaz,
 *    ondan bağımsız çalışır.
 */

defined( 'ABSPATH' ) || exit;

define( 'PAYTR_INLINE_VER', '1.1.0' );
define( 'PAYTR_INLINE_FILE', __FILE__ );
define( 'PAYTR_INLINE_DIR', plugin_dir_path( __FILE__ ) );
define( 'PAYTR_INLINE_URL', plugin_dir_url( __FILE__ ) );

add_action( 'plugins_loaded', 'paytr_inline_boot', 20 );

function paytr_inline_boot() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>PayTR Inline Checkout:</strong> WooCommerce etkin olmalı.</p></div>';
		} );
		return;
	}

	require_once PAYTR_INLINE_DIR . 'includes/class-paytr-api.php';
	require_once PAYTR_INLINE_DIR . 'includes/class-paytr-gateway.php';
	require_once PAYTR_INLINE_DIR . 'includes/class-paytr-controller.php';

	\PaytrInlineCheckout\Controller::instance();
}

register_activation_hook( __FILE__, function () {
	if ( ! wp_next_scheduled( 'paytr_inline_gc' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'paytr_inline_gc' );
	}
} );

register_deactivation_hook( __FILE__, function () {
	$ts = wp_next_scheduled( 'paytr_inline_gc' );
	if ( $ts ) {
		wp_unschedule_event( $ts, 'paytr_inline_gc' );
	}
} );
