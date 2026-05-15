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
- Mikro/MSSQL verisi Laravel’den direkt okunmaz; yetkili entegrasyon katmanı üzerinden okunur.
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

### 2.3 Admin QR/Link Üretimi

- Admin QR/link üretirken kullanıcı sadece Seri No girer.
- Ürün adı, model, marka, link tipi, aktivasyon kodu ve montaj durumu sistem tarafından seri bağlamından çözülür.
- Çözülen alanlar read-only gösterilir.
- Manuel düzeltme gerekiyorsa yalnızca yetkili admin için "Manuel düzeltme" advanced bölümü olabilir.
- Varsayılan akışta ürün adı/model/marka/link tipi elle zorunlu değildir.
- Link tipi otomatik sınıflandırılır:
  - seri için satış/fatura hareketi bulunamıyorsa veya bağlam güvenli değilse: pre_sale_product
  - seri için güvenli satış/fatura bağlamı varsa: sold_product
  - admin test amaçlı açarsa: manual_test
- Sorgu ürün/model/marka döndüremiyorsa link oluşturma kör şekilde devam etmemeli; admin'e "Seri bağlamı çözülemedi" diye net hata gösterilmelidir.
- link_type, QR/link bağlamının satış/fatura varlığına göre sınıflandırılmasıdır.
- customer_visible/customer_selectable ise müşteriye diğer ürünlerin gösterilip gösterilmeyeceğini belirler.
- invoice_customer_type dealer_or_partner olduğunda müşteriye fatura ürünleri gösterilmez; unknown tek başına gizleme sebebi değildir.
- Ancak seri için satış/fatura hareketi bulunduysa link_type sold_product olabilir.
- pre_sale_product yalnızca satış/fatura hareketi bulunamayan veya kutu ön baskı linkidir.
- Link tipi müşteri gizlilik politikasının yerine kullanılmaz.
- Seri geçmişinde satış/fatura izi olması tek başına bugünkü sahiplik anlamına gelmez.
- Güncel son hareket satış değilse, iade/depo/stok/merkez hareketi varsa veya güncel son satış doğrulanamıyorsa link satılmış ürün gibi yorumlanmaz.
- Ürün adı satış sorgusundan gelmiyorsa seri hareket geçmişindeki güvenli stok adı/model/marka alanlarından çözülebilir; ancak montaj durumu ve link tipi güncel sahiplik kontrolüne göre belirlenir.

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

current_serial_state:
- sold_current
- in_stock_or_center
- returned
- unknown

Önemli:
- current_serial_state = sold_current değilse Montaj Dahil varsayımı yapılmaz.
- current_serial_state = in_stock_or_center veya returned ise müşteri formu açılabilir, ancak operasyon kontrolü/retry state’i hazır tutulur.

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

## QR Açılış / Sorgu Bekleme Ekranı

QR okutulduğunda sistem seri/montaj/fatura bağlamını sorgular.

Eğer sorgu anlık tamamlanmıyorsa müşteri boş veya teknik hata ekranı görmez.

Müşteriye hoş tasarımlı bir bekleme ekranı gösterilir:

Başlık:
Formunuz hazırlanıyor

Metin:
Ürün bilgileriniz kontrol ediliyor. Lütfen kısa bir süre bekleyin, montaj talep formuna yönlendirileceksiniz.

Kurallar:
- Teknik sistem ifadeleri müşteriye gösterilmez.
- Bekleme ekranı sade, güven veren ve mobil uyumlu olur.
- Sorgu tamamlanınca doğru akışa yönlendirir:
  - montaj dahilse form
  - montaj hariçse ödeme / çoklu ürün seçimi
  - kontrol tamamlanamazsa form + operasyon kontrolü
- Uzun süren sorguda müşteri tekrar tekrar butona basmak zorunda kalmaz.
- Timeout olursa müşteri formdan engellenmez; operasyon kartında “Seri / montaj kontrolü bekliyor” state’i oluşur.

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

Form draft:
- Müşteri formu doldururken sayfayı kapatırsa aynı token/path ile geri geldiğinde müşteri verileri tarayıcı localStorage üzerinden geri yüklenir.
- Draft anahtarı token/path bazlıdır.
- Başarılı submit sonrası draft silinir.
- Draft sadece müşteri tarafından girilen temel form alanlarını tutar; token, secret, ödeme bilgisi veya server-derived context tutulmaz.

## 6. Müşteriye Ürün Gösterme Politikası
Public çoklu ürün seçiminde müşteriye gösterim sadece Sorumluluk Kodu ve iade durumuna göre yapılır.
Sorumluluk Kodu null/bos, PROJE, BAYI SATIŞ, ÇILINGIR SATISLARI veya GR ise satır müşteriye gösterilmez.
Iade satırlar da müşteriye gösterilmez.
PERAKENDE SATIŞ, ONLINE ve benzeri yasak listede olmayan sorumluluk kodları müşteriye gösterilebilir.
Bu satırlar operasyonda her zaman görünür.



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

Default görünürlük kuralı:
- dealer_or_partner ise müşteriye gösterme.
- unknown ise satır iade değilse müşteriye gösterilebilir.
- direct_customer ise satır iade değilse müşteriye gösterilebilir.

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
- sorumluluk_kodu_blocked_listede_değil


not_latest_sale:
- müşteriye gizleme sebebi değildir
- operasyon tarafında uyarı etiketi olarak gösterilir

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
- Bayi/proje/partner sebebiyle müşteriye gösterilmeyen ama iade olmayan seri
- Güncel son satış olmayan ama müşteri tarafında seçilebilirlikten gizlenmeyen seri için operasyon uyarısı

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
- customer_selectable
- customer_visible
- hidden_reason
- operation_added
- operation_added_by
- operation_added_at
- customer_phone
- linked_mrn
- operation_note
- warning_labels
- is_primary
- is_returned
- return_note
- return_date
- return_document_no
- is_current_latest_sale
- color_status
- invoice_customer_type
- source_payload

Amaç:
İleride aynı seriyle yeni iş gelirse operasyon eski MRN ilişkilerini görebilsin.

Çoklu ürün / fatura seri kayıt kuralı:
- Primary QR seri her zaman MRN'ye is_primary = true olarak bağlanır.
- Müşterinin seçtiği seriler customer_selected = true ve color_status = green olur.
- İade olmayan ama müşteri tarafından seçilmeyen veya gizlenen seriler color_status = orange olur.
- İade gelen seriler color_status = red olur.
- customer_selectable sadece sorumluluk_kodu_blogu_listede_degil ve is_returned = false ise true olur.
- customer_visible, müşteri tarafında gösterilebilir satırları ifade eder; bayi/proje/partner/toptan/kurumsal bayi ve iade satırları müşteriye gösterilmez.
- hidden_reason sadece responsibility_code_blocked, returned veya not_selected değerleriyle müşteri görünürlüğünü açıklar; unknown tek başına gizleme sebebi değildir.
- unknown otomatik gizleme sebebi değildir.
- is_current_latest_sale = false müşteriye gizleme sebebi değildir; yalnızca operasyon uyarısıdır.
- Müşteriye cari/fatura/bayi/hidden_reason/iade bilgisi gösterilmez.
- Public çoklu ürün seçiminde bayi/proje/partner/toptan/kurumsal bayi/distribütör/B2B sinyali olan satırlar müşteriye gösterilmez. Operasyon bu satırları görür ve iade değilse montaja ekleyebilir.
- Operasyon iade olmayan faturadaki tüm serileri sorumluluk kodundan bağımsız olarak montaja ekleyebilir veya çıkarabilir.
- İade seri montaja eklenemez.
- "Tüm uygun serileri montaja ekle" aksiyonu is_returned = false, primary olmayan ve zaten ekli olmayan tüm satırları ekler.
- Primary seri çıkarılamaz.
- Operasyon tarafından eklenen satırlarda operation_added = true olur; çıkarılan satırlarda operation_added = false ve operation_note = "Operasyon tarafından çıkarıldı" tutulur.
- Her seri satırında customer_phone ve linked_mrn tutulur; amaç seri → müşteri/MRN aramasını güçlendirmektir.
- Fatura seri sorgusu timeout veya hata verirse talep yine Yeni / Yeni Talep açılır; operasyon detayında fatura seri kontrolü bekliyor state'i ve tekrar kontrol endpoint'i hazır olur.

Sonraki faz backlog:
- OPS seri seçer.
- "Farklı adres için yeni talep oluştur" aksiyonu ile seçilen serilerden yeni MRN açılır.
- Müşteri bilgisi yeni MRN'ye taşınır.
- Adres formu operasyon tarafından düzenlenebilir.
- Ana MRN içinde ilişkili diğer montajlar görünür.
- Bu akış bu fazda kodlanmaz; bu fazda yalnızca UI taslak/bilgi mesajı bulunur.

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

QR formdan oluşan TechnicalServiceRequest üzerinde operasyonun ihtiyaç duyduğu QR context ayrıca saklanır:
- qr_link_id
- mount_session_id
- serial_number
- product_name
- product_model
- brand
- stock_code
- activation_code
- current_serial_state
- has_current_sale
- sale_mount_status
- mount_payment_status
- mount_payment_label
- mount_payment_provider
- mount_payment_reference
- mount_payment_paid_at
- invoice_series
- invoice_number
- invoice_display_no
- dispatch_series / irsaliye_seri
- dispatch_number / irsaliye_sira
- order_series / siparis_seri
- order_number / siparis_sira
- invoice_customer_type
- qr_context_payload

Gösterim kuralı:
- Satış montaj durumu ayrı gösterilir.
- Montaj ödeme durumu ayrı gösterilir.
- Montaj Hariç + ödeme paid ise doğru ifade:
  - Satış montaj durumu: Montaj Hariç
  - Montaj ödeme durumu: Montaj ödemesi alındı

Usta atama guard:
- operation_control.payment_checked = yes değilse usta atanamaz.
- operation_control.door_photos_checked = compatible değilse usta atanamaz.
- Guard backend tarafında zorunludur; sadece UI disable yeterli değildir.
- Hata metinleri:
  - “Usta atanamaz. Önce ödeme kontrolünü tamamlayın.”
  - “Usta atanamaz. Önce kapı görsellerini uygun olarak kontrol edin.”

## 12. Bildirim Akışı

Form gönderilince MRN oluştuktan sonra bildirim işi tetiklenir.

Kanallar:
- WhatsApp: bildirim entegrasyonu üzerinden
- SMS: bildirim entegrasyonu üzerinden

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

## 15. Sonraki Faz: Usta Randevu Onayı

Bu bölüm backlog sözleşmesidir; bu faz bu görevde kodlanmayacaktır.

Akış:
- OPS bir usta seçer.
- İş usta ekranına düşer.
- Usta uygun gün/saat aralığı belirler.
- Usta saat belirlediğinde OPS kartı “Müşteri onayı bekleniyor” durumuna gelir.
- OPS “Mesaj gönder” derse müşteriye WhatsApp/SMS şablonu gönderilir.

Örnek müşteri mesajı:

“{ürün} için {gün} {saat aralığı} montaj randevusu oluşturulmuştur. Randevuyu onaylıyor musunuz?”

Müşteri onaylarsa:
- ustaya bildirim gider
- müşteriye onay mesajı gider
- iş usta takvimine düşer
- usta kendi “İşlerim” ekranında işi görür
- usta iş detaylarını, fotoğrafları, yedek parça ve notları işler
- OPS kartı “Servis Atandı” kolonuna geçer

## 16. Plan Değişiklik Kuralı

Bu dosya ana sözleşmedir.

Eğer geliştirme sırasında plan değişirse:
- önce bu dosya güncellenir
- sonra kod değiştirilir
- final raporda “docs/technical-service-qr-mount-flow-v2.md güncellendi” açıkça yazılır

Bu dosya güncellenmeden yapılan plan dışı kod değişikliği kabul edilmez.
