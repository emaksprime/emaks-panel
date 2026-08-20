<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <title>Iyzico Sandbox Ödeme Bildirimi</title>
</head>
<body style="font-family: Arial, sans-serif; color: #0f172a; line-height: 1.5;">
    @php($mountPayment = (bool) ($details['mount_payment'] ?? false))
    <h1 style="font-size: 18px;">Iyzico Sandbox Ödeme Bildirimi</h1>
    <p>{{ $details['note'] ?? 'Bu bildirim provider reconciliation sonucu oluşturulmuştur.' }}</p>

    <h2 style="font-size: 15px; margin-top: 20px;">ORTAM</h2>
    <table cellpadding="6" cellspacing="0" border="0" style="border-collapse: collapse;">
        <tr><td><strong>Ödeme ortamı</strong></td><td>{{ $details['provider'] ?? 'Iyzico' }} {{ $details['provider_mode'] ?? 'Sandbox' }}</td></tr>
        <tr><td><strong>Mikro</strong></td><td>WRITE yapılmadı</td></tr>
    </table>

    <h2 style="font-size: 15px; margin-top: 20px;">ÖDEME</h2>
    <table cellpadding="6" cellspacing="0" border="0" style="border-collapse: collapse;">
        <tr><td><strong>TAHSİLAT AMACI</strong></td><td>{{ $details['payment_purpose_label'] ?? '-' }}</td></tr>
        <tr><td><strong>Tutar</strong></td><td>{{ $details['amount'] ?? '-' }} {{ $details['currency'] ?? 'TRY' }}</td></tr>
        <tr><td><strong>Ödeme zamanı</strong></td><td>{{ $details['paid_at'] ?? '-' }}</td></tr>
        <tr><td><strong>Provider link referansı</strong></td><td>{{ $details['provider_reference'] ?? 'Sağlayıcı tarafından dönmedi' }}</td></tr>
        <tr><td><strong>Provider ödeme ID</strong></td><td>{{ $details['provider_payment_reference'] ?? 'Sağlayıcı tarafından dönmedi' }}</td></tr>
        <tr><td><strong>Provider işlem ID</strong></td><td>{{ $details['provider_transaction_reference'] ?? 'Sağlayıcı tarafından dönmedi' }}</td></tr>
        <tr><td><strong>Host referansı</strong></td><td>{{ $details['provider_host_reference'] ?? 'Sağlayıcı tarafından dönmedi' }}</td></tr>
        <tr><td><strong>Dekont referansı</strong></td><td>{{ $details['provider_receipt_reference'] ?? 'Sağlayıcı tarafından dönmedi' }}</td></tr>
        <tr><td><strong>Provider durum</strong></td><td>{{ $details['provider_status'] ?? '-' }}</td></tr>
    </table>

    <h2 style="font-size: 15px; margin-top: 20px;">KAYIT</h2>
    <table cellpadding="6" cellspacing="0" border="0" style="border-collapse: collapse;">
        <tr><td><strong>MRN</strong></td><td>{{ $details['mrn'] ?? '-' }}</td></tr>
        <tr><td><strong>Root MRN</strong></td><td>{{ $details['root_mrn'] ?? '-' }}</td></tr>
        <tr><td><strong>SRV</strong></td><td>{{ $details['srv'] ?? '-' }}</td></tr>
        <tr><td><strong>Servis verilen ürün seri no</strong></td><td>{{ $details['related_product_serial'] ?? $details['serial_no'] ?? '-' }}</td></tr>
        <tr><td><strong>Müşteri</strong></td><td>{{ $details['customer_name'] ?? '-' }}</td></tr>
        <tr><td><strong>Telefon</strong></td><td>{{ $details['customer_phone'] ?? '-' }}</td></tr>
        <tr><td><strong>Fatura kişi/ünvan</strong></td><td>{{ data_get($details, 'billing.name') ?? '-' }}</td></tr>
        <tr><td><strong>Fatura kimlik</strong></td><td>{{ data_get($details, 'billing.identity') ?? '-' }}</td></tr>
        <tr><td><strong>Vergi dairesi</strong></td><td>{{ data_get($details, 'billing.tax_office') ?? '-' }}</td></tr>
        <tr><td><strong>Fatura adresi</strong></td><td>{{ data_get($details, 'billing.address') ?? '-' }} · {{ data_get($details, 'billing.district') ?? '-' }} / {{ data_get($details, 'billing.city') ?? '-' }}</td></tr>
        @unless ($mountPayment)
            <tr><td><strong>Teslim alacak kişi</strong></td><td>{{ data_get($details, 'shipping.name') ?? '-' }}</td></tr>
            <tr><td><strong>Teslim adresi</strong></td><td>{{ data_get($details, 'shipping.address') ?? '-' }} · {{ data_get($details, 'shipping.district') ?? '-' }} / {{ data_get($details, 'shipping.city') ?? '-' }}</td></tr>
            <tr><td><strong>Teslim biçimi</strong></td><td>{{ $details['delivery_mode'] ?? '-' }}</td></tr>
        @endunless
    </table>

    <h2 style="font-size: 15px; margin-top: 20px;">{{ $details['line_section_label'] ?? 'PARÇALAR' }}</h2>
    <table cellpadding="6" cellspacing="0" border="1" style="border-collapse: collapse; border-color: #cbd5e1;">
        <thead>
        <tr>
            <th>{{ $mountPayment ? 'Stok kodu / Hizmet' : 'Stok kodu / Parça' }}</th><th>Adet</th><th>Brüt birim</th><th>Brüt toplam</th><th>KDV</th><th>Net</th><th>KDV tutarı</th>
        </tr>
        </thead>
        <tbody>
        @forelse (($details['lines'] ?? []) as $line)
            <tr>
                <td><strong>{{ $line['item_code'] ?? '-' }}</strong><br>{{ $line['item_name'] ?? '-' }}</td>
                <td>{{ $line['quantity'] ?? '-' }} {{ $line['unit_code'] ?? '' }}</td>
                <td>{{ $line['gross_unit_price'] ?? '-' }}</td>
                <td>{{ $line['gross_line_total'] ?? '-' }}</td>
                <td>{{ $line['vat_rate'] ?? 'Doğrulanmadı' }}</td>
                <td>{{ $line['net_line_total'] ?? '-' }}</td>
                <td>{{ $line['vat_line_total'] ?? '-' }}</td>
            </tr>
        @empty
            <tr><td colspan="7">{{ $mountPayment ? 'Kanonik hizmet satırı bulunamadı.' : 'Kanonik parça satırı bulunamadı.' }}</td></tr>
        @endforelse
        </tbody>
    </table>

    <h2 style="font-size: 15px; margin-top: 20px;">TOPLAMLAR</h2>
    <table cellpadding="6" cellspacing="0" border="0" style="border-collapse: collapse;">
        <tr><td><strong>Müşteri brüt tahsilatı</strong></td><td>{{ $details['gross_total'] ?? '-' }}</td></tr>
        <tr><td><strong>Net toplam</strong></td><td>{{ $details['net_total'] ?? '-' }}</td></tr>
        <tr><td><strong>KDV toplamı</strong></td><td>{{ $details['vat_total'] ?? '-' }}</td></tr>
    </table>

    <h2 style="font-size: 15px; margin-top: 20px;">SİPARİŞ HAZIRLIĞI</h2>
    <table cellpadding="6" cellspacing="0" border="0" style="border-collapse: collapse;">
        <tr><td><strong>Hedef seri</strong></td><td>{{ $details['desired_series'] ?? '-' }}</td></tr>
        <tr><td><strong>Test simülasyon referansı</strong></td><td>{{ $details['simulation_reference'] ?? '-' }}</td></tr>
        <tr><td><strong>Context revizyonu</strong></td><td>{{ $details['context_revision'] ?? '-' }}</td></tr>
        <tr><td><strong>Context hash</strong></td><td style="word-break: break-all;">{{ $details['context_hash'] ?? '-' }}</td></tr>
        <tr><td><strong>Açıklama 2</strong></td><td>{{ $details['description2'] ?? '-' }}</td></tr>
        <tr><td><strong>Sonuç</strong></td><td><strong>GERÇEK MİKRO SİPARİŞİ OLUŞTURULMADI</strong></td></tr>
    </table>

    <p style="margin-top: 16px; color: #475569;">
        Sağlayıcı anahtarları, gizli değerler, imza/kimlik doğrulama başlıkları ve ham provider yanıtı bu e-postaya eklenmez.
    </p>
</body>
</html>
