/* global jQuery, paytrInline */
( function ( $ ) {
	'use strict';

	var CFG = window.paytrInline || {};
	var MOUNT = '#paytr-inline-mount';

	var App = {
		lastBin: '',
		selectedInstallment: 0,
		paying: false,
		pollTimer: null,
		pollDeadline: 0,

		start: function () {
			var $form = $( 'form.checkout' );

			$form.on( 'checkout_place_order_' + CFG.method, this.onPlaceOrder.bind( this ) );

			$( document ).on( 'input', '#paytr_card_number', this.onCardNumberInput.bind( this ) );
			$( document ).on( 'input', '#paytr_expiry', this.onExpiryInput.bind( this ) );
			$( document ).on( 'input', '#paytr_cvv', this.onDigitsOnly.bind( this ) );
			$( document ).on( 'click', '.paytr-inline-installment-row', this.onSelectInstallment.bind( this ) );
			$( document ).on( 'click', '.paytr-inline-3ds-close', this.close3ds.bind( this ) );
			$( document ).on( 'click', '#paytr-inline-showall', this.onToggleAllRates.bind( this ) );
		},

		$mount: function () {
			return $( MOUNT );
		},

		selected: function () {
			return $( 'input[name="payment_method"]:checked' ).val() === CFG.method;
		},

		/* ---- kart numarası: 4'lü gruplama + BIN sorgusu ---- */
		onCardNumberInput: function ( e ) {
			var $el = $( e.target );
			var digits = ( $el.val() || '' ).replace( /\D/g, '' ).slice( 0, 19 );
			var grouped = digits.replace( /(\d{4})(?=\d)/g, '$1 ' );
			$el.val( grouped );

			var bin = digits.slice( 0, 8 );
			if ( digits.length >= 6 ) {
				this.maybeLookupBin( bin.length >= 6 ? bin : digits.slice( 0, 6 ) );
			} else {
				this.clearInstallments();
			}
		},

		maybeLookupBin: function ( bin ) {
			if ( bin === this.lastBin ) {
				return;
			}
			this.lastBin = bin;
			var self = this;

			$.post( CFG.ajaxUrl, {
				action: 'paytr_inline_bin',
				nonce: CFG.nonce,
				bin: bin
			} ).done( function ( res ) {
				if ( bin !== self.lastBin ) {
					return; // kullanıcı yazmaya devam etti, eski yanıtı yok say
				}
				if ( ! res || ! res.success ) {
					self.clearInstallments();
					return;
				}
				self.renderInstallments( res.data.installments || [], res.data.brand || '', res.data.total || 0 );
			} ).fail( function () {
				self.clearInstallments();
			} );
		},

		renderInstallments: function ( installments, brand, total ) {
			var $wrap = $( '#paytr-inline-installments' );
			$( '#paytr_card_number' ).siblings( '.paytr-inline-card-brand' ).text( brand ? brand.toUpperCase() : '' );
			$( '#paytr_card_brand' ).val( brand || '' );
			$( '#paytr-inline-installments-heading' ).prop( 'hidden', false );

			var html = '<div class="paytr-inline-installment-row is-selected" data-count="0">' +
				'<span class="paytr-inline-radio"></span>' +
				'<span class="paytr-inline-installment-label">' + escapeHtml( CFG.i18n.noInstallment ) + '</span>' +
				'<span class="paytr-inline-installment-total">' + formatMoney( total ) + '</span>' +
				'</div>';

			installments.forEach( function ( row ) {
				html += '<div class="paytr-inline-installment-row" data-count="' + row.count + '">' +
					'<span class="paytr-inline-radio"></span>' +
					'<span class="paytr-inline-installment-label">' + row.count + ' X ' + formatMoney( row.perMonth ) + '</span>' +
					'<span class="paytr-inline-installment-total">' + formatMoney( row.total ) + '</span>' +
					'</div>';
			} );

			$wrap.html( html );
			this.selectedInstallment = 0;
			this.syncInstallmentField();
		},

		clearInstallments: function () {
			this.lastBin = '';
			this.selectedInstallment = 0;
			$( '#paytr-inline-installments-heading' ).prop( 'hidden', true );
			$( '#paytr-inline-installments' ).html(
				'<p class="paytr-inline-installments-placeholder">' + escapeHtml( CFG.i18n.installmentsPlaceholder ) + '</p>'
			);
			$( '#paytr_card_number' ).siblings( '.paytr-inline-card-brand' ).text( '' );
			$( '#paytr_card_brand' ).val( '' );
			this.syncInstallmentField();
		},

		onSelectInstallment: function ( e ) {
			var $row = $( e.currentTarget );
			$row.siblings().removeClass( 'is-selected' );
			$row.addClass( 'is-selected' );
			this.selectedInstallment = parseInt( $row.data( 'count' ), 10 ) || 0;
			this.syncInstallmentField();
		},

		syncInstallmentField: function () {
			var $mount = this.$mount();
			$mount.find( 'input[name="paytr_installment"]' ).remove();
			$( '<input type="hidden" name="paytr_installment">' )
				.val( this.selectedInstallment )
				.appendTo( $mount );
		},

		/* ---- "Tüm Taksit Seçeneklerini Göster" ---- */
		onToggleAllRates: function () {
			var $box = $( '#paytr-inline-allrates' );
			var $btn = $( '#paytr-inline-showall' );

			if ( ! $box.prop( 'hidden' ) ) {
				$box.prop( 'hidden', true );
				return;
			}

			if ( $box.data( 'loaded' ) ) {
				$box.prop( 'hidden', false );
				return;
			}

			$btn.prop( 'disabled', true );
			$.post( CFG.ajaxUrl, {
				action: 'paytr_inline_all_rates',
				nonce: CFG.nonce
			} ).done( function ( res ) {
				$btn.prop( 'disabled', false );
				if ( ! res || ! res.success || ! res.data.brands || ! res.data.brands.length ) {
					var msg = ( res && res.data && res.data.message ) ? res.data.message : CFG.i18n.generic;
					$box.html( '<p class="paytr-inline-allrates-row">' + escapeHtml( msg ) + '</p>' ).prop( 'hidden', false );
					return;
				}
				var html = '';
				res.data.brands.forEach( function ( b ) {
					html += '<div class="paytr-inline-allrates-brand">' + escapeHtml( b.brand.toUpperCase() ) + '</div>';
					b.installments.forEach( function ( row ) {
						html += '<div class="paytr-inline-allrates-row"><span>' + row.count + ' Taksit</span><span>' +
							formatMoney( row.perMonth ) + ' x ' + row.count + '</span></div>';
					} );
				} );
				$box.html( html ).data( 'loaded', true ).prop( 'hidden', false );
			} ).fail( function () {
				$btn.prop( 'disabled', false );
				$box.html( '<p class="paytr-inline-allrates-row">' + escapeHtml( CFG.i18n.generic ) + '</p>' ).prop( 'hidden', false );
			} );
		},

		/* ---- SKT: AA/YY maskesi -> gizli ay/yıl alanları ---- */
		onExpiryInput: function ( e ) {
			var $el = $( e.target );
			var digits = ( $el.val() || '' ).replace( /\D/g, '' ).slice( 0, 4 );
			var display = digits;
			if ( digits.length > 2 ) {
				display = digits.slice( 0, 2 ) + '/' + digits.slice( 2 );
			}
			$el.val( display );
			$( '#paytr_expiry_month' ).val( digits.slice( 0, 2 ) );
			$( '#paytr_expiry_year' ).val( digits.slice( 2, 4 ) );
		},

		onDigitsOnly: function ( e ) {
			var $el = $( e.target );
			$el.val( ( $el.val() || '' ).replace( /\D/g, '' ) );
		},

		/* ---- WooCommerce "Siparişi Ver": WC'nin kendi AJAX gönderimini iptal
		   edip (false döndürerek) formu kendimiz POST ediyoruz — iyzico inline
		   eklentisiyle aynı, kanıtlanmış desen. ---- */
		onPlaceOrder: function () {
			if ( ! this.selected() ) {
				return true;
			}
			if ( this.paying ) {
				return false;
			}

			var $form = $( 'form.checkout' );
			this.paying = true;
			this.block( $form );

			var self = this;
			$.post( CFG.checkoutUrl, $form.serialize() )
				.done( function ( raw ) {
					var res = parseJson( raw );

					if ( res && res.result === 'success' && res.redirect && res.redirect.indexOf( '#paytr-inline-3ds' ) !== -1 ) {
						var parts = res.redirect.split( '|' );
						self.startPayment( parts[ 1 ], parts[ 2 ] );
						return;
					}

					self.paying = false;
					self.unblock( $form );

					if ( res && res.result === 'success' && res.redirect ) {
						window.location = res.redirect;
						return;
					}

					if ( res && ( res.messages || res.message ) ) {
						self.renderRawMessages( res.messages || res.message );
					} else {
						self.renderNotice( [ CFG.i18n.generic ] );
					}
					self.scrollToTop();
				} )
				.fail( function () {
					self.paying = false;
					self.unblock( $form );
					self.renderNotice( [ CFG.i18n.generic ] );
					self.scrollToTop();
				} );

			return false;
		},

		block: function ( $form ) {
			if ( $form.data( 'blockUI.isBlocked' ) ) {
				return;
			}
			$form.addClass( 'processing' ).block( { message: null, overlayCSS: { background: '#fff', opacity: 0.6 } } );
		},

		unblock: function ( $form ) {
			$form.removeClass( 'processing' ).unblock();
		},

		scrollToTop: function () {
			var $t = $( 'form.checkout' );
			if ( $t.length ) {
				$( 'html, body' ).animate( { scrollTop: $t.offset().top - 100 }, 300 );
			}
		},

		renderRawMessages: function ( messages ) {
			var $wrap = $( '<div/>' ).html( messages );
			removeNotices();
			$( 'form.checkout' ).prepend( $wrap );
			$( document.body ).trigger( 'checkout_error', [ messages ] );
		},

		renderNotice: function ( lines ) {
			var html = '<ul class="woocommerce-error" role="alert">';
			( lines || [] ).forEach( function ( l ) {
				html += '<li>' + escapeHtml( l ) + '</li>';
			} );
			html += '</ul>';
			removeNotices();
			$( 'form.checkout' ).prepend( html );
			$( document.body ).trigger( 'checkout_error', [ html ] );
		},

		/* ---- Sipariş oluştu, PayTR isteği server tarafında atıldı.
		   Şimdi 3DS HTML'ini çekip iframe'de göster. ---- */
		startPayment: function ( orderId, orderKey ) {
			var self = this;

			if ( ! orderId || ! orderKey ) {
				self.paying = false;
				self.unblock( $( 'form.checkout' ) );
				self.renderNotice( [ CFG.i18n.generic ] );
				return;
			}

			this.currentOrder = { id: orderId, key: orderKey };
			this.show3dsLoading();

			$.post( CFG.ajaxUrl, {
				action: 'paytr_inline_get_3ds',
				nonce: CFG.nonce,
				order_id: orderId,
				order_key: orderKey
			} ).done( function ( res ) {
				if ( ! res || ! res.success || ! res.data || ! res.data.html ) {
					self.render3dsError( res && res.data && res.data.message ? res.data.message : CFG.i18n.generic );
					return;
				}
				self.render3ds( res.data.html );
				self.beginPolling( orderId, orderKey );
			} ).fail( function () {
				self.render3dsError( CFG.i18n.generic );
			} );
		},

		show3dsLoading: function () {
			this.unblock( $( 'form.checkout' ) ); // form artık overlay arkasında; kilitli tutmaya gerek yok
			var $overlay = $( '#paytr-inline-3ds-overlay' );
			$overlay.prop( 'hidden', false );
			var $box = $overlay.find( '.paytr-inline-3ds-box' );
			$box.find( '.paytr-inline-3ds-loading' ).remove();
			$box.append(
				'<div class="paytr-inline-3ds-loading"><span class="paytr-inline-spinner" aria-hidden="true"></span><span>' +
				escapeHtml( CFG.i18n.loading3ds ) + '</span></div>'
			);
		},

		render3ds: function ( html ) {
			var $overlay = $( '#paytr-inline-3ds-overlay' );
			$overlay.find( '.paytr-inline-3ds-loading' ).remove();
			var frame = document.getElementById( 'paytr-inline-3ds-frame' );
			if ( frame ) {
				frame.srcdoc = html;
			}
		},

		render3dsError: function ( msg ) {
			var $overlay = $( '#paytr-inline-3ds-overlay' );
			$overlay.find( '.paytr-inline-3ds-loading' ).remove();
			$overlay.find( '.paytr-inline-3ds-box' ).append(
				'<div class="paytr-inline-3ds-loading"><span>' + escapeHtml( msg ) + '</span></div>'
			);
		},

		close3ds: function () {
			$( '#paytr-inline-3ds-overlay' ).prop( 'hidden', true );
			var frame = document.getElementById( 'paytr-inline-3ds-frame' );
			if ( frame ) {
				frame.srcdoc = 'about:blank';
			}
			this.stopPolling();
			this.paying = false;
		},

		/* ---- 3DS tamamlanınca gerçek sonucu PayTR'nin bildirimi belirler;
		   burada yalnızca sipariş durumunu yoklayıp yönlendiriyoruz. ---- */
		beginPolling: function ( orderId, orderKey ) {
			var self = this;
			this.stopPolling();
			this.pollDeadline = Date.now() + 120000;

			this.pollTimer = setInterval( function () {
				if ( Date.now() > self.pollDeadline ) {
					self.stopPolling();
					self.render3dsError( CFG.i18n.timeout );
					return;
				}
				$.post( CFG.ajaxUrl, {
					action: 'paytr_inline_status',
					nonce: CFG.nonce,
					order_id: orderId,
					order_key: orderKey
				} ).done( function ( res ) {
					if ( ! res || ! res.success ) {
						return;
					}
					if ( 'paid' === res.data.status ) {
						self.stopPolling();
						window.location = res.data.redirect;
					} else if ( 'failed' === res.data.status ) {
						self.stopPolling();
						self.render3dsError( CFG.i18n.declined );
					}
				} );
			}, 2500 );
		},

		stopPolling: function () {
			if ( this.pollTimer ) {
				clearInterval( this.pollTimer );
				this.pollTimer = null;
			}
		}
	};

	function removeNotices() {
		$( '.woocommerce-error, .woocommerce-message, .woocommerce-NoticeGroup' ).remove();
	}

	function formatMoney( n ) {
		try {
			return new Intl.NumberFormat( 'tr-TR', { style: 'currency', currency: 'TRY' } ).format( n );
		} catch ( e ) {
			return n + ' TL';
		}
	}

	function escapeHtml( str ) {
		return String( str == null ? '' : str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	function parseJson( raw ) {
		if ( raw && typeof raw === 'object' ) {
			return raw;
		}
		if ( typeof raw !== 'string' ) {
			return null;
		}
		try {
			return JSON.parse( raw );
		} catch ( e ) {}
		var s = raw.indexOf( '{' );
		var e2 = raw.lastIndexOf( '}' );
		if ( s !== -1 && e2 !== -1 && e2 > s ) {
			try {
				return JSON.parse( raw.slice( s, e2 + 1 ) );
			} catch ( err ) {}
		}
		return null;
	}

	$( function () {
		if ( ! CFG.method || ! $( 'form.checkout' ).length ) {
			return;
		}
		App.start();
	} );
} )( jQuery );
