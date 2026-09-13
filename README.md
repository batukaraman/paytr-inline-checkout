# PayTR Inline Checkout for WooCommerce

WooCommerce checkout sayfasında **kendi kart formumuzu** (isim / numara / SKT /
CVC + dinamik taksit tablosu), ödeme yöntemi seçilir seçilmez **aynı sayfada,
akordeonun içinde** render eden bağımsız eklenti. Resmi "PayTR" WooCommerce
eklentisine **bağımlı değildir** — Direkt API (Direct API) entegrasyonu bu
eklenti içinde sıfırdan uygulanmıştır.

- Başarılı ödeme → Teşekkürler (Thank You) sayfası
- Başarısız ödeme → checkout'ta kalır, formun üstünde net hata
- 3D Secure → siteden çıkmadan, modal bir iframe içinde

## Bağımlılıklar

| Eklenti | Tür |
|---|---|
| WooCommerce | **Sert** (`Requires Plugins: woocommerce`) |
| Resmi "PayTR" eklentisi | **Yok** — kullanılmıyor, gerekmiyor |

## Kurulum

1. Klasörü `wp-content/plugins/paytr-inline-checkout/` altına koyup
   **Eklentiler** sayfasından etkinleştirin.
2. **WooCommerce → Ayarlar → Ödemeler → PayTR — Kart ile Öde (Inline)** kısmına
   girip PayTR Mağaza Paneli → Ayarlar → Bilgi Al ekranından `merchant_id`,
   `merchant_key`, `merchant_salt` bilgilerini girin.
3. PayTR Mağaza Paneli → Ayarlar → Bildirim URL alanına şunu yazın:
   ```
   https://SITENIZ/?wc-api=paytr_inline_notify
   ```
   Bu adres olmadan ödemeler PayTR tarafında onaylansa bile WooCommerce
   siparişi **tamamlanmış olarak işaretlenmez** (bkz. "Nasıl çalışır").

## Nasıl çalışır (özet)

1. `paytr_inline` adında bir `WC_Payment_Gateway` kaydedilir. `payment_fields()`
   kart alanlarını doğrudan `form.checkout` içine (adım/isim `paytr_*`) basar —
   üçüncü taraf bir widget yok, tüm DOM/CSS bizim.
2. Kart numarasının ilk 6-8 hanesi girilince `paytr_inline_bin` AJAX'ı BIN'i
   PayTR'nin `bin-detail` servisine sorar (banka/marka), sonucu mağazanın
   `taksit-oranlari` servisinden gelen (30 dk önbellekli) komisyon oranlarıyla
   birleştirip taksit tablosunu render eder.
3. **"Siparişi Ver"**: JS `checkout_place_order_paytr_inline` handler'ı WC'nin
   kendi AJAX gönderimini iptal eder (`false` döner), `form.checkout`'u
   (kart alanları dahil) manuel olarak `?wc-ajax=checkout`'a POST eder.
4. `Gateway::process_payment()` siparişi PayTR Direkt API'sine (`/odeme`)
   gönderir; kart alanları **hiçbir zaman** DB/log'a yazılmaz, tek istek
   ömründe bellekte tutulup hemen temizlenir (`class-paytr-api.php`).
   Dönen HTML (3D Secure ACS formu) 15 dakikalık bir transient'a konur ve
   `process_payment` sentinel `redirect: '#paytr-inline-3ds|<order_id>|<key>'`
   döner.
5. JS sentinel'i yakalar, `paytr_inline_get_3ds` AJAX'ı ile HTML'i **tek
   seferlik** çeker (transient hemen silinir) ve modal bir `<iframe srcdoc>`
   içinde gösterir. Kullanıcı 3D Secure adımını (SMS vb.) bu iframe içinde
   tamamlar.
6. Gerçek sonuç PayTR'nin **bildirim (webhook)** isteğiyle belirlenir
   (`woocommerce_api_paytr_inline_notify` → `Controller::handle_notify()`):
   hash doğrulanır, sipariş `payment_complete()` ile tamamlanır ya da
   `failed` yapılır. JS bu sırada `paytr_inline_status` ile ~2.5 sn'de bir
   siparişi yoklar; tamamlanınca Thank You sayfasına yönlendirir.
7. Terk edilmiş denemeler: saatlik `paytr_inline_gc` cron'u 2 saatten eski
   `pending`/`failed` siparişleri (`_paytr_inline_pending=yes`) `cancelled`
   yapar; My Account bu siparişlerin pay/cancel aksiyonlarını gizler.

## Güvenlik / PCI-DSS notu

Bu entegrasyon **PayTR Direkt API**'sini kullanır: kart numarası tarayıcıdan
`form.checkout` ile WordPress sunucusuna (tek bir HTTPS isteğiyle) gelir,
sunucu onu **hiçbir yere yazmadan** aynı anda PayTR'ye iletir ve bellekten
siler. Bu, resmi PayTR eklentisinin de kullandığı standart Direkt API
modelidir ve "iyzico Inline Checkout" eklentisindeki (kart verisinin hiç
sunucuya uğramadığı, iyzico'nun kendi hosted widget'ının kullanıldığı)
modelden farklı bir uyum kategorisine girer — tam **SAQ-A** için kart verisinin sunucuya HİÇ
değmemesi gerekir. Nihai PCI uyum kategorinizi (SAQ A-EP vb.) bankanız/PayTR
ile teyit edin; kod tarafında alınabilecek önlemlerin hepsi (kalıcı depolama
yok, log yok, TLS zorunlu) uygulanmıştır.

## Test kartları (PayTR sandbox / test modu)

Ayarlardan **Test Modu**'nu açıp PayTR'nin size verdiği test kartlarıyla
deneyin (banka/kart tipine göre değişir — PayTR Mağaza Paneli → Test Bilgileri).

## Geliştirme notları

- Taksit oranları (`oranlar`) PayTR'nin günlük güncellediği komisyon
  yüzdeleridir; `Api::installment_rates()` bunu 30 dakika önbellekler.
  Vade farkının müşteriye nasıl yansıtılacağı (fiyata ekleme oranı) PayTR
  Mağaza Paneli'ndeki taksit ayarlarınızla birebir örtüştüğünü canlıya
  almadan test siparişleriyle doğrulayın.
- Hash/parametre isimleri dev.paytr.com "Direkt API" (1. ve 2. adım),
  "BIN Sorgulama Servisi" ve "Taksit Oranları Sorgulama" sayfalarına göre
  yazılmıştır; PayTR dokümantasyonu güncellenirse `class-paytr-api.php`
  içindeki hash sırası/parametre adları buna göre revize edilmelidir.
