export const UNKNOWN_DELIVERY_WEEK = 'TESLİM TARİHİ BELİRSİZ';

const MONTH_NAMES = [
    'OCAK',
    'ŞUBAT',
    'MART',
    'NİSAN',
    'MAYIS',
    'HAZİRAN',
    'TEMMUZ',
    'AĞUSTOS',
    'EYLÜL',
    'EKİM',
    'KASIM',
    'ARALIK',
];

const CHART_EXCLUDED_KEYWORDS = ['SERV', 'HIZMET', 'HİZMET', 'MONTAJ', 'YOL', 'KESIF', 'KEŞ'];

export function numberValue(value) {
    const parsed = Number(value ?? 0);

    return Number.isFinite(parsed) ? parsed : 0;
}

export function textValue(row, keys, fallback = '') {
    for (const key of keys) {
        const value = row?.[key];

        if (value !== null && value !== undefined && String(value).trim() !== '') {
            return String(value).trim();
        }
    }

    return fallback;
}

export function normalizeOrderText(value) {
    return String(value ?? '')
        .trim()
        .toLocaleUpperCase('tr');
}

export function csvValues(value) {
    if (Array.isArray(value)) {
        return [...new Set(value.map((item) => String(item ?? '').trim()).filter(Boolean))];
    }

    return [...new Set(String(value ?? '').split(',').map((item) => item.trim()).filter(Boolean))];
}

export function brandKeyForRow(row) {
    const raw = normalizeOrderText(textValue(row, ['brand_key', 'marka', 'brand_code', 'brand_name'], ''));

    if (raw === 'PHILIPS') {
        return 'philips';
    }

    if (['EMAKS_PRIME', 'EMAKS PRIME', 'EMAKS'].includes(raw)) {
        return 'emaks_prime';
    }

    if (['OTHER', 'DİĞER MARKA'].includes(raw)) {
        return 'other';
    }

    return textValue(row, ['brand_key'], '') || 'other';
}

export function brandLabelForKey(key) {
    if (key === 'philips') {
        return 'PHILIPS';
    }

    if (key === 'emaks_prime') {
        return 'EMAKS PRIME';
    }

    return 'Diğer Marka';
}

export function orderProductLabel(row) {
    return textValue(row, ['urun_adi', 'stok_adi', 'model_adi', 'stok_kodu'], 'Ürün');
}

export function isChartEligibleOrderRow(row) {
    const haystack = normalizeOrderText([
        orderProductLabel(row),
        textValue(row, ['stok_adi']),
        textValue(row, ['model_adi']),
        textValue(row, ['sip_aciklama2']),
    ].join(' '));

    return haystack !== '' && !CHART_EXCLUDED_KEYWORDS.some((keyword) => haystack.includes(keyword));
}

export function productOptionsForRows(rows, brandFilter = 'all') {
    const options = new Map();

    for (const row of rows) {
        const brandKey = brandKeyForRow(row);

        if (brandFilter !== 'all' && brandKey !== brandFilter) {
            continue;
        }

        const label = orderProductLabel(row);

        if (!label) {
            continue;
        }

        const current = options.get(label) ?? {
            value: label,
            label,
            brandKey,
            brandLabel: brandLabelForKey(brandKey),
            quantity: 0,
            amount: 0,
            stockCode: textValue(row, ['stok_kodu'], ''),
        };

        current.quantity += numberValue(row.kalan_miktar ?? row.siparis_miktari);
        current.amount += numberValue(row.kalan_tutar ?? row.siparis_tutari);
        options.set(label, current);
    }

    return [...options.values()].sort((first, second) => first.label.localeCompare(second.label, 'tr'));
}

export function filterRowsForOrderDashboard(rows, filters = {}, mode = 'alinan') {
    const brandFilter = filters.brand_filter ?? 'all';
    const productTokens = csvValues(filters.product_filter).map(normalizeOrderText);
    const search = normalizeOrderText(filters.search);
    const deliveryWeek = filters.delivery_week ?? 'all';

    return rows.filter((row) => {
        if (brandFilter !== 'all' && brandKeyForRow(row) !== brandFilter) {
            return false;
        }

        if (productTokens.length > 0) {
            const productHaystack = normalizeOrderText([
                textValue(row, ['stok_kodu']),
                orderProductLabel(row),
                textValue(row, ['stok_adi']),
            ].join(' '));

            if (!productTokens.some((token) => productHaystack.includes(token))) {
                return false;
            }
        }

        if (search !== '') {
            const searchHaystack = normalizeOrderText([
                textValue(row, ['cari_adi']),
                textValue(row, ['urun_adi']),
                textValue(row, ['stok_adi']),
                textValue(row, ['stok_kodu']),
                textValue(row, ['sip_aciklama2']),
                textValue(row, ['temsilci_kodu']),
                textValue(row, ['sorumluluk_kodu']),
                textValue(row, ['stok_kategori_adi']),
            ].join(' '));

            if (!searchHaystack.includes(search)) {
                return false;
            }
        }

        if (mode === 'verilen' && deliveryWeek !== 'all' && deliveryWeekForRow(row) !== deliveryWeek) {
            return false;
        }

        return true;
    });
}

export function pieItemsForOrderRows(rows, quantityKey, amountKey = null) {
    const grouped = new Map();

    for (const row of rows) {
        if (!isChartEligibleOrderRow(row)) {
            continue;
        }

        const label = orderProductLabel(row);
        const quantity = numberValue(row[quantityKey]);
        const amount = amountKey ? numberValue(row[amountKey]) : 0;

        if (quantity <= 0) {
            continue;
        }

        if (!grouped.has(label)) {
            grouped.set(label, { label, quantity: 0, amount: 0 });
        }

        grouped.get(label).quantity += quantity;
        grouped.get(label).amount += amount;
    }

    const total = [...grouped.values()].reduce((sum, item) => sum + item.quantity, 0);

    return [...grouped.values()]
        .sort((first, second) => {
            const firstMetric = first.amount > 0 ? first.amount : first.quantity;
            const secondMetric = second.amount > 0 ? second.amount : second.quantity;

            return secondMetric - firstMetric || second.quantity - first.quantity || first.label.localeCompare(second.label, 'tr');
        })
        .slice(0, 8)
        .map((item) => ({
            ...item,
            percentage: total > 0 ? (item.quantity / total) * 100 : 0,
        }));
}

export function brandComparisonForOrderRows(rows, quantityKey, amountKey = null) {
    const grouped = new Map([
        ['philips', { key: 'philips', label: brandLabelForKey('philips'), quantity: 0, amount: 0 }],
        ['emaks_prime', { key: 'emaks_prime', label: brandLabelForKey('emaks_prime'), quantity: 0, amount: 0 }],
        ['other', { key: 'other', label: brandLabelForKey('other'), quantity: 0, amount: 0 }],
    ]);

    for (const row of rows) {
        if (!isChartEligibleOrderRow(row)) {
            continue;
        }

        const quantity = numberValue(row[quantityKey]);

        if (quantity <= 0) {
            continue;
        }

        const key = brandKeyForRow(row);
        const bucket = grouped.get(key) ?? grouped.get('other');

        bucket.quantity += quantity;
        bucket.amount += amountKey ? numberValue(row[amountKey]) : 0;
    }

    const items = [...grouped.values()].filter((item) => item.quantity > 0 || item.amount > 0);
    const totalAmount = items.reduce((sum, item) => sum + item.amount, 0);
    const totalQuantity = items.reduce((sum, item) => sum + item.quantity, 0);

    return items.map((item) => ({
        ...item,
        percentage: totalAmount > 0
            ? (item.amount / totalAmount) * 100
            : (totalQuantity > 0 ? (item.quantity / totalQuantity) * 100 : 0),
    }));
}

export function parseDateValue(value) {
    if (!value) {
        return null;
    }

    const raw = String(value).trim();
    const isoMatch = raw.match(/^(\d{4})-(\d{2})-(\d{2})/);

    if (isoMatch) {
        return new Date(Number(isoMatch[1]), Number(isoMatch[2]) - 1, Number(isoMatch[3]));
    }

    const trMatch = raw.match(/^(\d{2})\.(\d{2})\.(\d{4})/);

    if (trMatch) {
        return new Date(Number(trMatch[3]), Number(trMatch[2]) - 1, Number(trMatch[1]));
    }

    return null;
}

export function estimatedWeekLabel(value) {
    const date = parseDateValue(value);

    if (!date) {
        return UNKNOWN_DELIVERY_WEEK;
    }

    const day = date.getDate();
    const week = day <= 7 ? 1 : day <= 14 ? 2 : day <= 21 ? 3 : 4;

    return `${MONTH_NAMES[date.getMonth()]}'IN ${week}. HAFTASI`;
}

export function deliverySortValue(row) {
    const explicit = numberValue(row?.teslim_sira);

    if (explicit > 0) {
        return explicit;
    }

    const date = parseDateValue(textValue(row, ['teslim_tarihi', 'Teslim Tarihi']));

    if (!date) {
        return 99991231;
    }

    return Number(`${date.getFullYear()}${String(date.getMonth() + 1).padStart(2, '0')}${String(date.getDate()).padStart(2, '0')}`);
}

export function deliveryWeekForRow(row) {
    return textValue(row, ['tahmini_teslim_haftasi', 'teslim_tarihi_hafta'], '')
        || estimatedWeekLabel(textValue(row, ['teslim_tarihi', 'Teslim Tarihi']));
}

export function groupGivenOrders(rows) {
    const grouped = new Map();

    for (const row of rows) {
        const label = deliveryWeekForRow(row);
        const sort = deliverySortValue(row);

        if (!grouped.has(label)) {
            grouped.set(label, { label, sort, rows: [] });
        }

        grouped.get(label).rows.push(row);
        grouped.get(label).sort = Math.min(grouped.get(label).sort, sort);
    }

    return [...grouped.values()]
        .map((group) => ({
            ...group,
            rows: group.rows.sort((first, second) => textValue(first, ['stok_adi', 'Stok Adı']).localeCompare(textValue(second, ['stok_adi', 'Stok Adı']), 'tr')),
        }))
        .sort((first, second) => first.sort - second.sort || first.label.localeCompare(second.label, 'tr'));
}

export function deliveryWeekOptionsForRows(rows) {
    return groupGivenOrders(rows).map((group) => ({
        label: group.label,
        value: group.label,
    }));
}
