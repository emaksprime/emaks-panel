import { formatMoney, formatQuantity, numericValue } from './format';

const countFormatter = new Intl.NumberFormat('tr-TR', { maximumFractionDigits: 0 });

function formatCount(value) {
    return countFormatter.format(Number(value || 0));
}

const aliases = {
    cariKodu: ['cari_kodu', 'cariKod', 'CariKodu', 'kod', 'cari_kod', 'Cari Kod', 'Cari Kodu'],
    cariAdi: ['cari_adi', 'cariAdi', 'CariAdi', 'unvan', 'firma_unvani', 'firmaUnvani', 'FirmaUnvani', 'cari_unvan1', 'Cari', 'Firma Ünvanı'],
    cariGrup: ['cari_grup', 'cari_grup_adi', 'grup', 'CariGrup', 'cari_grup_kodu', 'Cari Grup Kodu'],
    telefon: ['telefon', 'phone', 'tel', 'Telefon'],
    email: ['email', 'mail', 'Eposta', 'E-posta'],
    il: ['il', 'sehir', 'city', 'İl'],
    ilce: ['ilce', 'district', 'İlçe'],
    bakiye: ['bakiye', 'balance', 'net_bakiye', 'Bakiye', 'Bakiye Durumu', 'genel_durum'],
    borc: ['borc', 'borç', 'debit', 'Toplam Borç', 'borc_bakiye'],
    alacak: ['alacak', 'credit', 'Toplam Alacak', 'alacak_bakiye'],
    temsilci: ['temsilci', 'temsilci_kodu', 'rep_code', 'sorumluluk_kodu', 'Temsilci'],
    sonHareket: ['son_hareket_tarihi', 'last_movement_date', 'tarih', 'Tarih', 'evrak_tarihi'],
    stokKodu: ['stok_kodu', 'Stok Kodu', 'stokKod', 'sto_kod'],
    urunAdi: ['urun_adi', 'stok_adi', 'stokAdi', 'model', 'model_adi', 'Ürün', 'Urun', 'stok_isim'],
    kategori: ['kategori_adi', 'kategori', 'Kategori', 'kategori_kodu'],
    depo: ['depo', 'Depo', 'depo_adi'],
    raf: ['raf', 'Raf', 'raf_kodu'],
    miktar: ['miktar', 'Miktar', 'stok_miktar', 'quantity', 'adet', 'toplam_miktar', 'siparis_miktar', 'siparis_miktari'],
    birim: ['birim', 'Birim', 'birim_adi'],
    siparisTarihi: ['siparis_tarihi', 'sip_tarih', 'tarih', 'Tarih', 'Sipariş Tarihi'],
    evrakNo: ['evrak_no', 'sip_evrakno_seri', 'sip_evrakno_sira', 'Evrak No', 'belge_no'],
    kalan: ['kalan_miktar', 'kalan', 'Kalan'],
    teslim: ['teslim_edilen', 'teslim_miktar', 'teslim_miktari', 'Teslim Edilen', 'gelen_miktar'],
    birimFiyat: ['birim_fiyat', 'fiyat', 'Birim Fiyat'],
    tutar: ['satir_tutari', 'tutar', 'Tutar', 'toplam', 'genel_toplam', 'net_tutar', 'sip_tutar'],
    durum: ['status', 'durum', 'Durum'],
    proformaNo: ['proforma_no', 'Proforma No'],
    createdAt: ['created_at', 'tarih', 'Tarih'],
};

export function valueFrom(row, key) {
    const keys = aliases[key] ?? [key];
    const found = keys.find((candidate) => Object.prototype.hasOwnProperty.call(row, candidate));

    return found ? row[found] : undefined;
}

export function normalizeSearchText(value) {
    return String(value ?? '')
        .toLocaleLowerCase('tr-TR')
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .replace(/ı/g, 'i');
}

const searchAliases = {
    cari: ['cariKodu', 'cariAdi', 'cariGrup', 'telefon', 'email', 'il', 'ilce', 'temsilci'],
    stock: ['stokKodu', 'urunAdi', 'kategori', 'depo', 'raf'],
    orders: ['siparisTarihi', 'evrakNo', 'cariAdi', 'urunAdi', 'durum'],
    proforma: ['proformaNo', 'cariAdi', 'durum', 'createdAt'],
};

export function filterRowsForSearch(kind, rows, search) {
    const needle = normalizeSearchText(search);

    if (!needle) {
        return rows;
    }

    const keys = searchAliases[kind] ?? [];

    return rows.filter((row) => keys.some((key) => normalizeSearchText(valueFrom(row, key)).includes(needle)));
}

function findColumn(columns, key, label) {
    const keys = aliases[key] ?? [key];
    const found = keys.map((candidate) => columns.find((column) => column.key === candidate)).find(Boolean);

    return found ? { ...found, label } : null;
}

export function moduleKindFromPage(page) {
    const slug = page.slug ?? '';
    const routePath = page.routePath ?? '';

    if (slug.startsWith('cari') || routePath.startsWith('/cari') || routePath.startsWith('/crm/customers')) {
        return 'cari';
    }

    if (slug.startsWith('stock') || routePath.startsWith('/stock')) {
        return 'stock';
    }

    if (slug.startsWith('orders') || routePath.startsWith('/orders')) {
        return 'orders';
    }

    if (slug.startsWith('proforma') || routePath.startsWith('/proforma')) {
        return 'proforma';
    }

    return 'module';
}

export function pageCopy(page, kind) {
    const routePath = page.routePath ?? '';

    if (kind === 'cari') {
        if (routePath.includes('/balance')) {
            return {
                title: 'Müşteri Bakiyesi',
                description: 'Borç, alacak ve net bakiye takibi',
                eyebrow: 'Müşteri Yönetimi',
            };
        }

        return {
            title: 'Müşteri Yönetimi',
            description: 'Müşteri, bayi ve hesap bilgileri',
            eyebrow: 'Müşteri Yönetimi',
        };
    }

    if (kind === 'stock') {
        return {
            title: 'Stok Yönetimi',
            description: 'Ürün, depo ve raf stok listesi',
            eyebrow: 'Stok Yönetimi',
        };
    }

    if (kind === 'orders') {
        if (routePath.includes('/verilen')) {
            return {
                title: 'Verilen Siparişler',
                description: 'Tedarikçi siparişleri ve kalan miktar takibi',
                eyebrow: 'Sipariş Yönetimi',
            };
        }

        if (routePath.includes('/alinan')) {
            return {
                title: 'Alınan Siparişler',
                description: 'Müşteri siparişleri ve teslimat takibi',
                eyebrow: 'Sipariş Yönetimi',
            };
        }

        return {
            title: 'Sipariş Yönetimi',
            description: 'Alınan ve verilen siparişlerin operasyon takibi',
            eyebrow: 'Sipariş Yönetimi',
        };
    }

    if (kind === 'proforma') {
        return {
            title: routePath.includes('/create') ? 'Proforma Oluştur' : 'Proforma Yönetimi',
            description: 'Proforma listesi, taslak satırları ve yazdırma hazırlığı',
            eyebrow: 'Proforma',
        };
    }

    return {
        title: page.title,
        description: page.description,
        eyebrow: page.heroEyebrow,
    };
}

export function preferredColumns(kind, page, columns) {
    const routePath = page.routePath ?? '';
    const definitions = {
        cari: routePath.includes('/balance')
            ? [
                ['cariKodu', 'Müşteri Kodu'],
                ['cariAdi', 'Müşteri Adı / Ünvan'],
                ['cariGrup', 'Grup'],
                ['borc', 'Borç'],
                ['alacak', 'Alacak'],
                ['bakiye', 'Net Bakiye'],
                ['temsilci', 'Temsilci'],
                ['sonHareket', 'Son Hareket'],
            ]
            : [
                ['cariKodu', 'Müşteri Kodu'],
                ['cariAdi', 'Müşteri Adı / Ünvan'],
                ['cariGrup', 'Grup'],
                ['telefon', 'Telefon'],
                ['il', 'İl'],
                ['ilce', 'İlçe'],
                ['bakiye', 'Bakiye'],
                ['temsilci', 'Temsilci'],
                ['sonHareket', 'Son Hareket'],
            ],
        stock: [
            ['urunAdi', 'Ürün / Model'],
            ['miktar', 'Miktar'],
        ],
        orders: routePath.includes('/verilen')
            ? [
                ['siparisTarihi', 'Sipariş Tarihi'],
                ['evrakNo', 'Evrak No'],
                ['cariAdi', 'Tedarikçi'],
                ['urunAdi', 'Ürün / Model'],
                ['miktar', 'Sipariş Miktarı'],
                ['teslim', 'Gelen'],
                ['kalan', 'Kalan'],
                ['tutar', 'Tutar'],
                ['durum', 'Durum'],
            ]
            : [
                ['siparisTarihi', 'Sipariş Tarihi'],
                ['evrakNo', 'Evrak No'],
                ['cariAdi', 'Müşteri'],
                ['urunAdi', 'Ürün / Model'],
                ['miktar', 'Sipariş Miktarı'],
                ['teslim', 'Teslim Edilen'],
                ['kalan', 'Kalan'],
                ['birimFiyat', 'Birim Fiyat'],
                ['tutar', 'Tutar'],
                ['durum', 'Durum'],
            ],
        proforma: [
            ['proformaNo', 'Proforma No'],
            ['cariAdi', 'Müşteri'],
            ['durum', 'Durum'],
            ['createdAt', 'Tarih'],
            ['tutar', 'Genel Toplam'],
        ],
    };

    const preferred = (definitions[kind] ?? [])
        .map(([key, label]) => findColumn(columns, key, label))
        .filter(Boolean);

    return preferred.length > 0 ? preferred : columns.slice(0, 8);
}

export function summaryCards(kind, page, rows, cartItems = []) {
    if (kind === 'cari') {
        const borc = rows.reduce((sum, row) => sum + Math.max(0, numericValue(valueFrom(row, 'borc'))), 0);
        const alacak = rows.reduce((sum, row) => sum + Math.max(0, numericValue(valueFrom(row, 'alacak'))), 0);
        const net = rows.reduce((sum, row) => sum + numericValue(valueFrom(row, 'bakiye')), 0);

        return [
            { label: 'Toplam Müşteri', value: formatCount(rows.length), hint: 'Listelenen kayıt' },
            { label: 'Borç Bakiyesi', value: formatMoney(borc), hint: 'Pozitif borç toplamı' },
            { label: 'Alacak Bakiyesi', value: formatMoney(alacak), hint: 'Alacak toplamı' },
            { label: 'Net Bakiye', value: formatMoney(net), hint: 'Seçili liste toplamı' },
        ];
    }

    if (kind === 'stock') {
        const totalStock = rows.reduce((sum, row) => sum + numericValue(valueFrom(row, 'miktar')), 0);
        const criticalCount = rows.filter((row) => numericValue(valueFrom(row, 'miktar')) > 0 && numericValue(valueFrom(row, 'miktar')) <= 5).length;

        return [
            { label: 'Kayıt', value: formatCount(rows.length), hint: 'Listelenen ürün' },
            { label: 'Toplam Stok', value: formatQuantity(totalStock), hint: 'Seçili liste miktarı' },
            { label: 'Kritik Stok', value: formatCount(criticalCount), hint: 'Düşük miktarlı ürün' },
            { label: 'Proforma Sepeti', value: formatCount(cartItems.length), hint: `${formatMoney(cartItems.reduce((sum, item) => sum + Number(item.quantity || 1) * Number(item.unit_price || 0), 0))}` },
        ];
    }

    if (kind === 'orders') {
        const totalAmount = rows.reduce((sum, row) => sum + numericValue(valueFrom(row, 'tutar')), 0);
        const remaining = rows.reduce((sum, row) => sum + numericValue(valueFrom(row, 'kalan')), 0);

        return [
            { label: page.routePath?.includes('/verilen') ? 'Verilen Sipariş' : 'Alınan Sipariş', value: formatCount(rows.length), hint: 'Listelenen satır' },
            { label: 'Kalan Miktar', value: formatQuantity(remaining), hint: 'Açık miktar toplamı' },
            { label: 'Toplam Tutar', value: formatMoney(totalAmount), hint: 'Seçili liste toplamı' },
            { label: 'Durum', value: rows.length > 0 ? 'Güncel Liste' : '-', hint: 'Filtre sonucuna göre' },
        ];
    }

    if (kind === 'proforma') {
        const total = rows.reduce((sum, row) => sum + numericValue(valueFrom(row, 'tutar')), 0);

        return [
            { label: 'Proforma', value: formatCount(rows.length), hint: 'Listelenen kayıt' },
            { label: 'Ara Toplam', value: formatMoney(total), hint: 'Seçili liste toplamı' },
            { label: 'KDV', value: formatMoney(0), hint: 'Satır detayında hesaplanır' },
            { label: 'Genel Toplam', value: formatMoney(total), hint: 'Yazdırma öncesi kontrol' },
        ];
    }

    return [
        { label: 'Kayıt', value: formatCount(rows.length), hint: 'Listelenen satır' },
        { label: 'Durum', value: rows.length > 0 ? 'Veri Var' : '-', hint: 'Filtre sonucuna göre' },
        { label: 'Başlangıç', value: '-', hint: 'Filtre' },
        { label: 'Bitiş', value: '-', hint: 'Filtre' },
    ];
}

export function detailTitle(kind, row) {
    if (!row) {
        return 'Kayıt detayı';
    }

    if (kind === 'cari') {
        return String(valueFrom(row, 'cariAdi') ?? valueFrom(row, 'cariKodu') ?? 'Müşteri detayı');
    }

    if (kind === 'orders') {
        return String(valueFrom(row, 'evrakNo') ?? valueFrom(row, 'cariAdi') ?? 'Sipariş detayı');
    }

    if (kind === 'stock') {
        return String(valueFrom(row, 'urunAdi') ?? valueFrom(row, 'stokKodu') ?? 'Stok detayı');
    }

    if (kind === 'proforma') {
        return String(valueFrom(row, 'proformaNo') ?? valueFrom(row, 'cariAdi') ?? 'Proforma detayı');
    }

    return 'Kayıt detayı';
}

export function friendlyEmptyMessage(kind) {
    if (kind === 'cari') {
        return 'Müşteri kaydı bulunamadı.';
    }

    if (kind === 'orders') {
        return 'Sipariş veri kaynağı henüz tanımlı değil veya seçili filtrelerde kayıt bulunamadı.';
    }

    if (kind === 'proforma') {
        return 'Proforma veri kaynağı henüz tanımlı değil veya seçili filtrelerde kayıt bulunamadı.';
    }

    if (kind === 'stock') {
        return 'Stok veri kaynağı henüz tanımlı değil veya seçili filtrelerde kayıt bulunamadı.';
    }

    return 'Seçili filtreler için kayıt bulunamadı.';
}
