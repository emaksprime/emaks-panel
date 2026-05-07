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
