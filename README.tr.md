# GiveWP için Paymos Stabil Coin Bağışları

[English](README.md) | [简体中文](README.zh-CN.md) | [Русский](README.ru.md) | [Español](README.es.md) | [Deutsch](README.de.md) | **Türkçe**

GiveWP'de Paymos barındırılan ödeme sayfası aracılığıyla tek seferlik stabil coin bağışları kabul edin. Paymos, GiveWP'nin Visual ve klasik bağış formlarına stabil coin ödeme yöntemi ekler. Bağışçılar Paymos ödeme sayfasını sitenizdeki durum sayfasından açar; eklenti ödemeyi Paymos ile doğruladıktan sonra GiveWP onaylanmış bağışı kaydeder ve yerel makbuzunu sunar.

[![İzleyin: 12 Resmi CMS Kripto Eklentisi — Tek Tıkla Bağlantı (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)
> **Video anlatım (1:12, 6 dilde altyazı):** [YouTube'da izleyin](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Genel Bakış

Paymos Stablecoin Donations, hem Visual hem klasik GiveWP formlarında tek seferlik bağışlar için Paymos barındırılan ödeme sayfasını doğrudan entegre eder.

- Bağışçılar, WordPress sitenizdeki özel durum sayfasından Paymos ödeme sayfasını başlatır.
- Ödeme onayı hiçbir zaman yalnızca tarayıcı yönlendirmesine dayanmaz — GiveWP, imzalı webhook bildirimleri ve Merchant API doğrulaması ödemeyi onayladıktan sonra bağışı kaydeder ve yerel makbuzu sunar.
- WordPress yönetim panelinden doğrudan tam iade ve fazla ödeme iadesi destekler.
- GPLv2 veya üzeri lisanslıdır ([LICENSE](LICENSE)). Güncel kararlı sürüm: `1.0.1`.

## Gereksinimler

- WordPress `6.9` veya üzeri (`7.1` sürümüne kadar test edilmiştir)
- GiveWP `4.19.1.1` veya üzeri
- PHP `7.4` veya üzeri
- HTTPS kullanan bir WordPress sitesi
- Bir Paymos hesabı ve projesi
- Eklentiyi her sitede ayrı ayrı etkinleştirin (tek seferlik bağışlar desteklenir)

## Kurulum ve Bağlantı

1. GiveWP eklentisini kurup etkinleştirin, ardından **Paymos Stablecoin Donations** eklentisini (WordPress.org Eklenti Dizini veya [Releases](https://github.com/Paymos-labs/givewp/releases) üzerinden) kurup etkinleştirin.
2. **GiveWP → Settings → Payment Gateways → Paymos** ekranını açın. GiveWP'nin ödeme moduyla eşleşecek şekilde **Sandbox** veya **Live** seçeneğini belirleyin.
3. **Connect Paymos** düğmesine tıklayın ve Paymos onay sayfasında site ile projeyi onaylayın.
4. Stabil coin kabul etmek istediğiniz Visual veya klasik formlarda Paymos'u etkinleştirin.

## Harici Hizmetler ve Güvenlik

Bu ödeme ağ geçidinin kullandığı barındırılan ödeme sayfası ve Merchant API, Paymos tarafından sağlanır. [Paymos Kullanım Koşulları](https://paymos.io/tr/terms) ve [Gizlilik Politikası](https://paymos.io/tr/privacy) belgelerini inceleyebilirsiniz.

**Bağlantı.** Bir yönetici bağlantıyı başlattığında WordPress, `https://app.paymos.io/connect/cms/device` adresine `client_id=paymos-cms` ve `scope=cms:bootstrap` gönderir. Tarayıcı onay bağlantısı `givewp` eklenti tanımlayıcısını, site URL'sini, kullanıcı kodunu ve aynı kaynağa (origin) ait olduğunda yönetici dönüş URL'sini taşır. Onay sürecinde WordPress, `https://app.paymos.io/connect/token` uç noktasını cihaz kodu yetkisi (`device_code`), `client_id` ve gizli `device_code` ile yoklar. WordPress seçilen ortamın kimlik bilgilerini şifrelenmiş bir seçenekte saklar ve kısa ömürlü OAuth belirtecini atar.

**Bağışlar.** Bağışçı Paymos ödeme sayfasını açtığında WordPress, `https://api.paymos.io` adresine `project_id`, `amount`, `currency` ve rastgele bir `external_order_id` ile `POST /v1/invoices` isteği gönderir. Bağışçı bilgileri ve GiveWP makbuz kimlik bilgileri WordPress'te kalır. Eklenti ilgili faturayı `GET /v1/invoices/{invoice_id}` ile doğrular ve kesintiye uğrayan bir isteği proje ve özgün referans üzerinden `GET /v1/invoices` ile arayabilir. Bu istekler anahtar kimliği, zaman damgası ve HMAC imzası taşır. İmzalama gizli anahtarı sitenizde kalır.

**Ödeme ve onay.** Bağışçılar barındırılan faturayı `https://checkout.paymos.io` adresinde açar. Paymos, bağlı Paymos projesiyle ilişkilendirilmiş WordPress webhook'una imzalı fatura bildirimleri gönderir. Eklenti imzayı doğrular, API üzerinden ilgili faturayı çeker ve GiveWP'yi güncellemeden önce kapsamı ve ödemeyi denetler. WordPress'in zamanlanmış mutabakat görevi de bekleyen işlemleri API üzerinden kontrol eder.

## İadeler

Yetkili personel **GiveWP → Paymos refunds** bölümünden tam iade veya fazla ödeme iadesi talep edebilir. WordPress, `invoice_id`, `mode` ve rastgele bir `external_order_id` içeren imzalı bir `POST /v1/refunds` isteği gönderir. Paymos hedef adresi özgün ödemeden belirler.

Eklenti işlemi `GET /v1/refunds/{withdrawal_id}` ve gerektiğinde fatura kapsamındaki `GET /v1/refunds` listesi üzerinden kontrol eder. GiveWP, Paymos işleminin tamamlandığını doğruladıktan sonra tam iadeyi kaydeder. Fazla ödenen tutarın iadesi özgün bağış durumunu korur.

## Sıkça Sorulan Sorular

**Bağışçı sayfa yenilendikten sonra bağışa nasıl devam edebilir?**

Tarayıcı kurtarma bağlantısını saklayabildiğinde bağışçı aynı form üzerinde **Resume your previous Paymos donation** seçeneğini kullanabilir. Bağlantı sitenin durum sayfasını açar ve 12 saat içinde sona erer. Site, durumu veya makbuzu göstermeden önce özgün tarayıcıyı ve GiveWP eşleşmesini doğrular. Normal bir yeni gönderim ayrı bir bağış başlatır.

**Eklentiyi ayırdığımda veya sildiğimde ne olur?**

Ödeme ağ geçidini devre dışı bırakmak yeni Paymos bağışlarını durdurur ve sonuçlanmamış işlemleri kontrol etmek için gereken kimlik bilgilerini korur. Eklentiyi pasifleştirmek zamanlanmış kontrollerini durdurur. Eklentiyi silmek yerel kimlik bilgilerini, durum sayfalarına tarayıcı erişimini ve zamanlanmış görevleri kaldırır. GiveWP bağış geçmişini korur. Eklenti, bir yöneticinin elle inceleyebilmesi için sonuçlanmamış ödeme ve iadelere ait asgari referansları tutar. Uzak anahtarları ve webhook'ları Paymos projesinden yönetin.

## Destek

- Dokümantasyon: [paymos.io/tr/docs/cms-givewp](https://paymos.io/tr/docs/cms-givewp)
- Kaynak kodu ve sürümler: [Paymos-labs/givewp](https://github.com/Paymos-labs/givewp)
- Destek: Paymos ödeme sayfası, bağlantı veya iadelerle ilgili yardım için [support@paymos.io](mailto:support@paymos.io) adresine ulaşın.
- Lisans: GPLv2 veya üzeri ([LICENSE](LICENSE))
