# Teknik Servis QR / Montaj Talep Akışı v2

Bu dosya Teknik Servis QR/link, montaj ödeme, müşteri formu, çoklu ürün talebi, fatura seri/iade görünürlüğü, operasyon kartı ve bildirim akışının ana sözleşmesidir. Bundan sonraki Codex işleri bu dosyaya göre yapılacaktır. Plan değişirse bu dosya aynı iş içinde güncellenecektir.

## 1. Temel Prensipler

- Satılmamış ürünler için QR/link üretilecek.
- QR/link üretim anında ürün satılmadığı ve faturası olmadığı için sadece şu bilgiler kesin kabul edilir:
  - Ürün adı
  - Model
  - Seri no
  - Marka
- QR/link müşteri tarafından açıldığında sistem seri üzerinden montaj durumunu kontrol eder.
- Mikro/MSSQL verisi Laravel’den direkt okunmaz; n8n/DataSource gateway üzerinden okunur.
- Müşteri gereksiz ürün/fatura/cari bilgisi görmez.
- Operasyon tüm teknik ve fatura-seri detaylarını görebilir.
- Müşteri formu gönderince müşteri bekletilmez; talep alınır mesajı gösterilir.
- Operasyon kartı her zaman Yeni / Yeni Talep olarak oluşur.
- İnceleniyor statüsü otomatik atanmaz.
- Plan değişirse bu doküman güncellenmeden kod geliştirme yapılmaz.

## 2. QR / Link Türleri

### 2.1 Satılmamış Ürün QR/Linki

Bu link kutuya yapıştırılır.

Tutabileceği alanlar:
- serial_number
- product_name
- product_model
- brand

Fatura yoksa fatura-seri bilgisi beklenmez.

### 2.2 Satılmış Ürün / Sonradan Oluşan Link

Satış/fatura bilgisi varsa seri üzerinden fatura-seri sorgusu yapılabilir.

## 3. Ana State Alanları

sale_mount_status ayrı alandır:
- unknown
- not_found
- montaj_dahil
- montaj_sonradan_dahil
- montaj_haric
- check_failed

mount_payment_status ayrı alandır:
- not_required
- pending
- paid
- failed
- cancelled
- skipped_multi_product

customer_entry_mode:
- single_product
- paid_single_product
- included_mount
- multi_product_without_payment

decision_status:
- pending_check
- decision_ready
- form_open
- submitted
- check_timeout

Önemli:
- Montaj Hariç, ödeme alınmadı demek değildir.
- Doğru örnek:
  - Satış montaj durumu: Montaj Hariç
  - Montaj ödeme durumu: Montaj ödemesi alındı

## 4. Müşteri Karar Ağacı

QR/link açılır.

1. Seri bağlamı okunur.
2. Montaj durumu kontrol edilir.
3. Karar ekranı çıkar.

### 4.1 Montaj Dahil / Montaj Sonradan Dahil

- Ödeme gerekmez.
- Form direkt açılır.
- mount_payment_status = not_required
- Formun altında:
  “Bu adres için birden fazla ürün montaj talebim var”
  tiki görünür.

### 4.2 Montaj Hariç

Müşteriye iki seçenek gösterilir:

1. Montaj ödemesi yap
2. Birden fazla ürün için montaj talebim var

#### 4.2.1 Ödeme Yapılırsa

- Ödeme başarılı olunca form açılır.
- mount_payment_status = paid
- Form içinde çoklu ürün tiki görünmez.
- Çünkü müşteri tek ürün için ödeme yapmıştır.

#### 4.2.2 Birden Fazla Ürün İçin Montaj Talebi

- Ödeme alınmaz.
- Form açılır.
- mount_payment_status = skipped_multi_product
- Form içinde tekrar çoklu ürün tiki görünmez.
- Operasyon kartında şu uyarı görünür:
  “Müşteri birden fazla ürün montaj talebi iletti. Müşteri ile iletişime geçiniz.”

### 4.3 Kontrol Timeout / Mikro Kontrol Edilemedi

- Müşteri bekletilmez.
- Form açılabilir.
- Kart Yeni / Yeni Talep olarak oluşur.
- Kartta:
  “Seri / montaj kontrolü bekliyor”
  görünür.
- Operasyonda “Tekrar kontrol et” state’i hazır olur.

## 5. Form Submit Kuralı

Müşteri formu gönderdiğinde:

- MRN hemen oluşturulur.
- Müşteriye hemen:
  “Montaj talebiniz alınmıştır”
  mesajı gösterilir.
- Teknik servis kartı hemen oluşur.

Zorunlu alanlar:
- status = Yeni
- workflow_status = Yeni Talep
- source_channel = qr_mount_form
- priority = Orta
- risk_level = Orta

Yasak:
- status = İnceleniyor
- workflow_status = İnceleniyor
- otomatik operasyon inceleme transition’ı
- eksik operasyon kontrolü nedeniyle İnceleniyor’a atma

## 6. Müşteriye Ürün Gösterme Politikası

Müşteriye aynı faturadaki ürünler varsayılan olarak gösterilmez.

Sebep:
Ürün bayiden alınmış olabilir. Bizdeki son çıkış bayiye ise müşteri aslında o faturanın tarafı değildir. O faturadaki tüm ürünleri müşteriye göstermek veri gizliliği açısından zayıf fikirdir.

Müşteriye aynı faturadaki ürün seçimi sadece güvenli durumda gösterilebilir:

- Son satış faturası doğrudan son müşteriye ait olmalı.
- Cari bayi/proje/partner olmamalı.
- Seri iade olmamalı.
- Seri bu fatura için güncel son satış olmalı.
- invoice_customer_type = direct_customer olmalı.

Eğer:
- invoice_customer_type = dealer_or_partner
- invoice_customer_type = unknown
- cari bayi/proje/partner görünüyorsa
- güvenli sınıflandırma yapılamıyorsa

müşteriye ürün listesi gösterilmez ve ekrana gereksiz mesaj basılmaz. Form akışı devam eder.

## 7. Bayi / Final Müşteri Tespiti

Fatura seri sorgusuna mümkünse şu alanlar eklenmelidir:

- Satış Cari Grup Kodu
- Satış Cari Grup Adı

Bunlar CARI_HESAPLAR ve CARI_HESAP_GRUPLARI üzerinden gelir.

Servis sınıflandırması:

invoice_customer_type:
- direct_customer
- dealer_or_partner
- unknown

Default güvenlik kuralı:
- unknown ise müşteriye gösterme.
- dealer_or_partner ise müşteriye gösterme.
- direct_customer ise ve satır iade değilse müşteriye gösterilebilir.

## 8. Fatura Seri / İade Mantığı

Fatura seri sorgusu şu alanları döndürür:
- Faturadaki Seri No
- Stok Kodu
- Stok Adı
- İade Notu
- İade Tarihi
- İade Evrak Seri
- İade Evrak Sıra
- Bu Fatura Bu Seri İçin Son Satış mı
- Serinin Güncel Son Satış alanları

İade tespiti:
- İade Notu doluysa iade sayılır.
- İade Tarihi doluysa iade sayılır.

Müşteri seçimine çıkma kuralı:
customer_selectable = true sadece şu durumda:
- is_returned = false
- is_current_latest_sale = true
- invoice_customer_type = direct_customer

Operasyon tarafı her şeyi görür:
- talep edilen seriler
- aynı faturadaki diğer seriler
- müşteriye gösterilmeyen seriler
- iade gelen seriler

## 9. Operasyon Renk Kuralı

Operasyon detayında renkler:

Yeşil:
- Müşterinin seçtiği / talep ettiği seri

Turuncu:
- Aynı faturadaki ama müşteri tarafından seçilmeyen seri
- Bayi/unknown sebebiyle müşteriye gösterilmeyen ama iade olmayan seri

Kırmızı:
- İade gelen seri
- Müşteriye gösterilmemesi gereken iade/uygunsuz seri

İade satırında yazı ile göster:
- İade gelen seri
- Müşteriye gösterilmedi
- İade tarihi
- İade evrak seri/sıra
- Güncel son satış durumu

## 10. Operasyon Kartı / MRN İlişkisi

Her seri MRN’ye bağlanmalıdır.

technical_service_request_serials alanları:
- technical_service_request_id
- mrn
- serial_number
- product_name
- product_model
- brand
- stock_code
- invoice_series
- invoice_number
- customer_selected
- customer_visible
- hidden_reason
- is_primary
- is_returned
- return_note
- return_date
- return_document_no
- invoice_customer_type
- source_payload

Amaç:
İleride aynı seriyle yeni iş gelirse operasyon eski MRN ilişkilerini görebilsin.

## 11. Operasyon Kontrolü

Kapı fotoğrafları saha tamamlama belgesi değildir.

Kapı fotoğrafları sadece burada gösterilir:

Operasyon Kontrolü
→ Kapı görselleri bakıldı mı?

Altında:
- Kapı ön yüzü
- Kapı yan yüzü
- Kapı arka yüzü
- thumbnail / dosya adı / görüntüle linki

Kontroller:
- Ödeme kontrol edildi mi? Evet/Hayır
- Adres kontrol edildi mi? Evet/Hayır
- Kapı görselleri bakıldı mı? Uyumlu/Uyumsuz/Kontrol edilmedi
- Eksik bilgi var mı? Var/Yok
- Müşteri aranacak mı? Evet/Hayır
- Randevu tarihi güncellenecek mi? Evet/Hayır

Olumlu yeşil.
Olumsuz/problem kırmızı.
Nötr gri/turuncu.

## 12. Bildirim Akışı

Form gönderilince MRN oluştuktan sonra n8n notification job tetiklenir.

Kanallar:
- WhatsApp: n8n → Evo API
- SMS: n8n → SMS API

İlk şablon:

“Sayın {ad_soyad},
{urun_adi} ürününüz için {mrn} kodlu montaj talebiniz alınmıştır.
Emaks Prime operasyon ekibi en kısa sürede sizinle iletişime geçecektir.”

Bildirimler ayrı işte yapılacaktır.
Bu doküman sadece sözleşmeyi tutar.

## 13. Google Places / Adres

Google Places müşterinin adres seçimini kolaylaştırır.

Kurallar:
- Key hardcode edilmez.
- VITE_GOOGLE_MAPS_API_KEY kullanılır.
- Public müşteriye Google/API/key teknik metni gösterilmez.
- Places veya current location seçilirse İl/İlçe/Adres doldurulur.
- Eğer reverse geocode başarısızsa il/ilçe uydurulmaz.

Adres ek alanları:
- Bina No
- Daire No
- Kapı No
- Kat
- Site/Apartman adı

## 14. Production Checklist

Bu iş canlıya alınırken unutulmaması gerekenler:

- Coolify env:
  - VITE_GOOGLE_MAPS_API_KEY
  - VITE_GOOGLE_MAPS_LANGUAGE=tr
  - VITE_GOOGLE_MAPS_REGION=TR

- Browser key restriction:
  - https://dashboard.emaksprime.com.tr/*

- API restrictions:
  - Maps JavaScript API
  - Places API / Places API (New)
  - Geocoding API

- Routes API:
  - Usta/müşteri yol, km, fiyat hesabı için ileride gerekir.
  - Ayrı server key daha doğru.
  - VITE_ yapılmaz.

- Deploy sonrası:
  - php artisan migrate --force
  - optimize/cache/route/view clear

- Upload limit kontrolü:
  - post_max_size
  - upload_max_filesize
  - max_file_uploads

Gerçek API key veya secret bu dokümana yazılmaz.

## 15. Plan Değişiklik Kuralı

Bu dosya ana sözleşmedir.

Eğer geliştirme sırasında plan değişirse:
- önce bu dosya güncellenir
- sonra kod değiştirilir
- final raporda “docs/technical-service-qr-mount-flow-v2.md güncellendi” açıkça yazılır

Bu dosya güncellenmeden yapılan plan dışı kod değişikliği kabul edilmez.
