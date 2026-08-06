# Technical Service Customer Contact Log Plan

- **Status:** PLANNED
- **Owner:** Burhan
- **Project:** EMAKS Panel / PR92
- **Normative RFP:** `EMAKS_Prime_Panel_vNext_RFP_SRS_v1.0.docx`
- **Updated:** 2026-08-06
- **Dependency:** Port 8000 allowlisted message delivery manual PASS

## Amaç

Teknik Servis modallarında yapılan müşteri aramalarını kullanıcı bazlı, MRN/SRV ilişkili ve değiştirilemez Operation History kayıtları olarak tutmak.

Yeni paralel CRM veya audit sistemi kurulmayacaktır.

**Canonical owner:** Mevcut `OperationEvent` / Operation History altyapısı.

## 1. UI Aksiyonu

Teknik Servis detay/modal içinde **Müşteri Arandı** aksiyonu bulunur.

Sonuç seçenekleri:

- Ulaşıldı.
- Ulaşılamadı.
- Meşgul.
- Yanlış Numara.
- Geri Aranacak.
- Müşteri Daha Sonra Aranacak.
- Gelen Arama.
- Diğer.

Alanlar:

- Sonuç.
- Operasyon notu.
- Görüşme tarihi/saati.
- Tekrar aranacak tarih/saati, opsiyonel.
- Kanal: Telefon.
- İlgili root MRN.
- İlgili current MRN veya SRV.

## 2. Canonical Kayıt Alanları

Mevcut event modelinin gerçek alan adları implementation inventory ile belirlenecektir. Bu plan yeni tablo veya ikinci audit modeli varsaymaz.

Gerekli semantik alanlar:

- `root_request_id`.
- `root_mrn`.
- `request_id`.
- `request_code`.
- `current_mrn_or_srv`.
- `actor_user_id`.
- `actor_name_snapshot`.
- `event_key = customer_contact_logged`.
- `channel = phone`.
- `contact_result`.
- `note`.
- `contacted_at`.
- `callback_at`, nullable.
- Metadata schema version.
- Idempotency key.
- `created_at`.

Telefon numarası event metadata içinde tekrar ham PII olarak çoğaltılmaz. Telefon request/customer kaydından çözülür veya yalnız maskeli fingerprint tutulur.

## 3. MRN/SRV Görünürlük Kuralı

### Root MRN Operation History

- Root MRN olaylarını gösterir.
- Tüm child SRV temas kayıtlarını kronolojik olarak gösterir.

### SRV Detail Operation History

- İlgili SRV temas kayıtlarını gösterir.
- Root bağlamını gösterir.
- Direct parent ilişkisini gösterir.

Her satırda aşağıdaki bilgiler görünür:

- Tarih/saat.
- Kullanıcı.
- MRN/SRV.
- Sonuç.
- Not.
- Callback zamanı.

Örnek:

> 06.08.2026 10:42
>
> Mehmet Burhan Pekgüzel
>
> Müşteri arandı - Ulaşılamadı
>
> SRV-...
>
> Not: Saat 18:00 sonrası tekrar aranacak.

## 4. Yetki ve Audit

- OPS ve Admin kayıt ekleyebilir.
- Partner yalnız kendi kapsamı açıkça yetkilendirilirse kayıt ekleyebilir.
- Başka tenant veya partner kayıtları görünmez.
- Actor current authenticated userdır.
- Actor adı snapshot olarak saklanır.
- Eski kayıt sessizce düzenlenmez.
- Düzeltme gerekirse yeni correction event eklenir.
- Delete yoktur.
- Aynı submit veya çift tıklama duplicate event üretmez.

## 5. Modal Davranışı

- İlk clickte loading/disabled state gösterilir.
- Tek HTTP mutation yapılır.
- Duplicate submit count `0` olmalıdır.
- Başarı toastı görünür.
- Modal kapanmaz.
- Scroll ve selected request korunur.
- Operation History targeted refresh ile yenilenir.
- Full board reload yapılmaz.
- Sayfa refresh gerekmez.

## 6. Kabul Kriterleri

### Backend

- `customer_contact_event_records_authenticated_actor`.
- `customer_contact_event_links_root_mrn_and_current_srv`.
- `callback_at_is_optional`.
- `contact_result_is_allowlisted`.
- `duplicate_submit_creates_one_event`.
- `another_partner_cannot_view_or_create_contact_log`.
- `historical_event_is_immutable`.
- `correction_creates_new_event`.

### Frontend / Browser

- `customer_called_modal_opens`.
- `success_feedback_visible`.
- `modal_does_not_remount`.
- `history_updates_without_page_refresh`.
- `root_history_shows_child_srv_contact`.
- Raw enum görünmez.
- Actor ve timestamp görünür.

### Migration Kararı

- Mevcut event metadata gerekli alanları güvenli biçimde taşıyorsa `migration=0`.
- Mevcut model alanları semantiği, sorgu performansını, immutability veya tenant izolasyonunu güvenli biçimde karşılamıyorsa bu docs taskında migration yazılmaz.
- Migration kararı ayrı implementation inventory sonucunda verilir.

## 7. Kapsam Dışı

- Full CRM.
- Voibot çağrı kaydı importu.
- Çağrı kaydı veya ses dosyası.
- Otomatik arama.
- Anket modülü.
- Yeni ayrı audit tablosu.
- Mikro veya n8n değişikliği.
- Global MRN implementasyonu.
