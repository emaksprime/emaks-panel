# EMAKS Panel vNext — Current Execution Plan

- **Status:** CURRENT
- **Owner:** Burhan
- **Project:** EMAKS Panel / PR92
- **Normative RFP:** `EMAKS_Prime_Panel_vNext_RFP_SRS_v1.0.docx`
- **Service Platform RFP:** OUT OF SCOPE
- **Updated:** 2026-08-06

## Current Git and Handoff Identity

This identity was captured at the documentation preflight. The candidate HEAD below is the base of this documentation commit; the resulting documentation commit SHA is reported after commit and is not self-referentially embedded in this file.

| Authority | Exact value |
|---|---|
| Candidate repository | `C:\dev\emaks-panel-public-origin-final-candidate` |
| Candidate branch | `burhan/pilot-mikro-readonly-foundation` |
| Documentation base HEAD | `677d62fe08e742ba4a628624e85f1d5fa3181df6` |
| Documentation base tree | `8fc84f9b866843719f0b0f55d56abb30bbf1085c` |
| Runtime path | `C:\dev\emaks-panel-runtime-bd9ed6e` |
| Runtime HEAD | `bc3bc87ea4f6dde12809610ffdc86a1b5ebccd20` |
| Runtime tree | `6b5688c2933ec1e6f6dc9ebedadd9eb186f4ad5d` |
| Handoff revision | `60` |
| Handoff state | `PR92_POSITIVE_FLOW_REPAIR_IMPLEMENTED_WAITING_BURHAN_MANUAL_ACCEPTANCE` |
| Handoff-recorded candidate HEAD | `bc3bc87ea4f6dde12809610ffdc86a1b5ebccd20` |
| Handoff manifest SHA-256 | `6d2757159c6e72d1456ee686ad0ca67d2e418c409f5c9e3d5fa22a1da2162dae` |
| Handoff next action | `BURHAN_POSITIVE_FLOW_MANUAL_RETEST` |

The candidate contains a local, unpushed child commit that is not active on port 8000. Runtime acceptance and Handoff authority therefore remain separate from candidate source identity until a controlled runtime update and Burhan PASS.

## 1. Sabit Kapsam

### PRESERVE

- PR92 Mikro API entegrasyonu.
- Mikro operation catalog, canary, parity ve source-mode sözleşmeleri.
- n8n fallback ve dashboard data paths.
- Public-origin, tenant ve runtime güvenliği.
- Mevcut payment, message ve workflow canonical sahipleri.
- Sales, Stock ve Orders.
- B2B ve Teknik Servis çalışan yapıları.

### REFERENCE ONLY

- PR88'de çalıştığı kanıtlanmış business davranışları yalnız beklenen davranış referansıdır.
- PR88 branchine toplu dönüş, cherry-pick veya mimari geri alma yapılmaz.

### REPAIR

- Güncel manuel testte bulunan gerçek kusurlar.
- Sender ve runtime ayar davranışı.
- Payment, message, earning ve UI tutarlılığı.

### DEFER

- Global MRN implementasyonu.
- Teknik Servis CRM.
- Geniş UI redesign.
- Production deploy.

Bu başlıklar mevcut temel tekrar Burhan PASS almadan başlamaz.

## 2. Geliştirme Çalışma Modeli

Yeni sabit çalışma modeli:

1. Worktree clean preflight.
2. Evidence-based kısa inventory.
3. Aynı kökten gelen işlerin tek konsolide pakette ele alınması.
4. Focused test.
5. Gerektiğinde tek full CI-equivalent kapı.
6. Sabit port 8000 runtime güncellemesi.
7. Burhan manuel UI testi.
8. Burhan PASS sonrası normal fast-forward push.
9. Remote CI.
10. Sonraki package için yeni Burhan kararı.

Kurallar:

- Her küçük düzeltme için yeni runtime klasörü oluşturulmaz.
- Mikro-fix başına bağımsız cutover yapılmaz.
- Çalışan alan gereksiz yere refactor edilmez.
- Burhan PASS olmadan push yapılmaz.
- FAIL halinde kusurlar tek listede konsolide edilir.
- Aynı problem için sonsuz verifier/correction döngüsü açılmaz.

## 3. Port 8000 Çalışma Ortamı

Port 8000:

- Tek yerel manuel kabul ortamıdır.
- Gerçek kullanıcı akışlarının test edildiği yerdir.
- Test numaralarıyla gerçek provider teslimi kontrollü olarak doğrulanabilir.
- Candidate ve runtime HEAD değerleri ayrı ayrı exact görünmelidir.
- Yeni commit runtime'a alınmadan Burhan'a test yaptırılmaz.

Current snapshot:

- Port 8000 runtime HEAD: `bc3bc87ea4f6dde12809610ffdc86a1b5ebccd20`.
- Local manual acceptance profile kapalıdır.
- `real_send=false`, queue paused ve message sender owner count `0` durumundadır.
- Persistent allowlisted local message delivery henüz kabul edilmemiştir; PHASE 1 açık blokerdır.

## 4. Mesaj Gönderim Kararı

Burhan product decision:

| Gerçek gönderim | Test modu | Davranış |
|---|---|---|
| Kapalı | Açık/Kapalı | Dispatch loglanır, dış sağlayıcıya gitmez |
| Açık | Açık | Yalnız ayarlardaki test telefonlarına gider |
| Açık | Kapalı | Form/request içindeki gerçek müşteri, usta ve OPS numaralarına gider |

Kurallar:

- Test telefonları source code veya workflow JSON içine hardcode edilmez.
- Admin/settings authority kullanılır.
- Test modu rol bazlı customer, technician ve OPS test hedefi kullanabilir.
- Test modu kapalıysa actual recipient kullanılır.
- Normal business flow temporary UAT run istemez.
- Normal business flow `expected_body_token` istemez.
- Tek `MessageDispatch`/outbox kullanılır.
- Tek provider sender kullanılır.
- WhatsApp providerı Evolution'dır.
- SMS providerı NAC'tır.
- Mail providerı SMTP'dir.
- Kill switch korunur.
- Idempotency ve duplicate koruması korunur.
- Eski suppressed kayıtlar otomatik backlog olarak gönderilmez.
- Kullanıcı OPS detayında `prepared`, `suppressed`, `queued`, `sent` ve `failed` sonuçlarını görür.

Bu karar yalnız local development/manual acceptance için persistent çalışma modelidir. Dated RFP snapshotındaki scoped UAT active-run kuralı, normal business flow için bu kararla genişletilemez; normal flow ile explicit scoped UAT birbirinden ayrı kalır.

Production açılışında aşağıdaki koşulların tamamı zorunludur:

- Gerçek gönderim açık.
- Test modu kapalı.
- Production provider readiness PASS.
- Secret ve domain kontrolleri PASS.

## 5. Veri Sahipliği

### PostgreSQL Authority

- Teknik Servis workflow.
- MRN/SRV operasyonel bağları.
- CRM ve temas kayıtları.
- B2B kullanıcı ve portal kayıtları.
- Payment local projection.
- Message dispatch ve audit.
- Documents.
- Operation History.
- ERP dışı uygulama verileri.

### Mikro API Authority

- Cari.
- Stok.
- Seri.
- Sipariş.
- İrsaliye.
- Fatura.
- Proforma.
- ERP document/state.
- Cari açma.
- Siparişe dönüştürme.
- ERP write işlemleri.

Mikro write kuralları:

- ERP write yalnız resmi Mikro API üzerinden yapılır.
- Doğrudan MSSQL write yapılmaz.

### Snapshot Modeli

1. İlk kurulumda bounded toplu Mikro API snapshot alınır.
2. PostgreSQL içinde ERP mirror/projection tutulur.
3. Değişiklik bulma için `modified_at`, API cursor veya değişiklik zamanı kullanılır.
4. Canonical kimlik Mikro GUID'dir.
5. Delta GUID üzerinden upsert edilir.
6. İptal, pasif ve statü değişiklikleri de işlenir.
7. Periyodik full reconciliation çalışır.
8. Kullanıcıya freshness ve last-sync gösterilir.
9. Snapshot ERP authority değildir; performans projectionıdır.

### n8n

- Geçiş, fallback veya MSSQL read yolu olabilir.
- Nihai ERP business authority değildir.
- Sales, Stock ve Orders için mevcut operation-specific source-mode kararları korunur.

## 6. Güncel Modül Durumu

| Alan | Durum | Sonraki işlem |
|---|---|---|
| B2B yönetimi | Büyük ölçüde yapıldı | Küçük manuel düzeltmeler |
| Teknik Servis akışı | Büyük ölçüde yapıldı | Positive-flow kusurları kapatılacak |
| Payment/earning | Yapıldı | E2E tutarlılık kontrolü |
| WhatsApp/SMS | Altyapı yapıldı | Ayar/sender davranışı çalıştırılacak |
| Muhasebe maili | Çalıştığı kanıtlandı | Duplicate korunacak |
| Mikro typed API | Yapıldı | Coverage ve canary audit |
| n8n/Mikro parity | Yapıldı | Operation-level audit |
| Snapshot/delta mirror | Eksik | Ayrı package |
| Global MRN | Kısmi | Ayrı canonical package |
| Teknik Servis CRM | Yapılacak | Global MRN sonrasında |
| UI iyileştirmeleri | Kısmi | Kritik correctness önce |
| Form UI düzeltmeleri | Az sayıda kaldı | Ayrı küçük package |
| Customer contact logs | Planlandı | Mesaj teslimi sonrasında |

## 7. Uygulama Sırası

### PHASE 1 - Port 8000 Message Delivery

Port 8000 mesaj ayar/sender davranışını basitleştir. Burhan test telefonlarında WhatsApp ve SMS teslimini doğrular.

### PHASE 2 - Customer Contact Log

`Müşteri Arandı` ve iletişim sonuçları Operation History'ye eklenir.

### PHASE 3 - Teknik Servis Positive Flow

Talep -> ödeme -> atama -> randevu -> belge -> müşteri onayı -> parça/SRV -> completion -> hakediş zinciri doğrulanır.

### PHASE 4 - Consolidated Correctness Repair

Kalan payment, earning ve UI kusurları tek consolidated package ile kapatılır.

### PHASE 5 - n8n + Mikro Coverage Audit

Port 8000 üzerinde operation-level n8n ve Mikro coverage audit yapılır.

### PHASE 6 - Mikro Snapshot + Delta Mirror

Mikro initial snapshot ve GUID-temelli delta mirror uygulanır.

### PHASE 7 - Global MRN

Canonical cari, customer, product, serial, MRN ve SRV bağları uygulanır.

### PHASE 8 - Teknik Servis CRM

Teknik Servis CRM ile İşlerim/Bildirim Merkezi uygulanır.

### PHASE 9 - UI/Form

Kalan UI ve form düzenlemeleri tamamlanır.

### PHASE 10 - Production Readiness

`dashboard.emaksprime.com.tr` için HTTPS, secrets, worker/scheduler, backup, monitoring, rollback ve deploy kapıları tamamlanır.

## 8. Unutulmaması Gereken Başlıklar

- İşlerim / Bildirim Merkezi.
- Entegrasyon hata kutusu.
- Audit / Operation History.
- RBAC ve tenant final audit.
- Snapshot freshness ekranı.
- Worker, scheduler ve provider sağlık ekranı.
- Backup / restore.
- Monitoring / logging.
- Secret rotation.
- HepsiJet ayrı package.
- Voibot ayrı package.
