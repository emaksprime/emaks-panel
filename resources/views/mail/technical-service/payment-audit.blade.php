<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <title>EMAKS Teknik Servis Ödeme Bildirimi</title>
</head>
<body style="font-family: Arial, sans-serif; color: #0f172a; line-height: 1.5;">
    <h1 style="font-size: 18px;">EMAKS Teknik Servis Ödeme Bildirimi</h1>
    <p>{{ $details['note'] ?? 'Bu bildirim provider reconciliation sonucu oluşturulmuştur.' }}</p>

    <table cellpadding="6" cellspacing="0" border="0" style="border-collapse: collapse;">
        <tr><td><strong>MRN</strong></td><td>{{ $details['mrn'] ?? '-' }}</td></tr>
        <tr><td><strong>Root MRN</strong></td><td>{{ $details['root_mrn'] ?? '-' }}</td></tr>
        <tr><td><strong>Seri no</strong></td><td>{{ $details['serial_no'] ?? '-' }}</td></tr>
        <tr><td><strong>Müşteri</strong></td><td>{{ $details['customer_name'] ?? '-' }}</td></tr>
        <tr><td><strong>Telefon</strong></td><td>{{ $details['customer_phone'] ?? '-' }}</td></tr>
        <tr><td><strong>Tutar</strong></td><td>{{ $details['amount'] ?? '-' }} {{ $details['currency'] ?? 'TRY' }}</td></tr>
        <tr><td><strong>Ödeme zamanı</strong></td><td>{{ $details['paid_at'] ?? '-' }}</td></tr>
        <tr><td><strong>Sağlayıcı</strong></td><td>{{ $details['provider'] ?? 'Iyzico' }} {{ $details['provider_mode'] ?? '' }}</td></tr>
        <tr><td><strong>Link token</strong></td><td>{{ $details['provider_reference'] ?? '-' }}</td></tr>
        <tr><td><strong>Provider ödeme referansı</strong></td><td>{{ $details['provider_payment_reference'] ?? '-' }}</td></tr>
        <tr><td><strong>Provider işlem referansı</strong></td><td>{{ $details['provider_transaction_reference'] ?? '-' }}</td></tr>
        <tr><td><strong>Dekont referansı</strong></td><td>{{ $details['provider_receipt_reference'] ?? 'Sağlayıcı tarafından dönmedi' }}</td></tr>
        <tr><td><strong>Provider durum</strong></td><td>{{ $details['provider_status'] ?? '-' }}</td></tr>
    </table>

    <p style="margin-top: 16px; color: #475569;">
        Sağlayıcı anahtarları, gizli değerler, imza/kimlik doğrulama başlıkları ve ham provider yanıtı bu e-postaya eklenmez.
    </p>
</body>
</html>
