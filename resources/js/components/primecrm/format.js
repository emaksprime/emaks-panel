const dateFormatter = new Intl.DateTimeFormat('tr-TR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
});

const dateTimeFormatter = new Intl.DateTimeFormat('tr-TR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
});

const moneyFormatter = new Intl.NumberFormat('tr-TR', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

const numberFormatter = new Intl.NumberFormat('tr-TR', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

function parseNumeric(value) {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    if (typeof value === 'number') {
        return Number.isFinite(value) ? value : null;
    }

    const normalized = String(value)
        .trim()
        .replace(/\s/g, '')
        .replace(/\./g, '')
        .replace(',', '.')
        .replace(/[^\d.-]/g, '');

    if (normalized === '' || normalized === '-' || normalized === '.') {
        return null;
    }

    const parsed = Number(normalized);

    return Number.isFinite(parsed) ? parsed : null;
}

function parseDateValue(value) {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    if (value instanceof Date) {
        return Number.isNaN(value.getTime()) ? null : value;
    }

    if (typeof value === 'number') {
        const asMilliseconds = value > 9999999999 ? value : value * 1000;
        const date = new Date(asMilliseconds);

        return Number.isNaN(date.getTime()) ? null : date;
    }

    const raw = String(value).trim();

    if (!raw) {
        return null;
    }

    const sqlDate = raw.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/);

    if (sqlDate) {
        const [, year, month, day, hour = '00', minute = '00', second = '00'] = sqlDate;
        const date = new Date(Number(year), Number(month) - 1, Number(day), Number(hour), Number(minute), Number(second));

        return Number.isNaN(date.getTime()) ? null : date;
    }

    const trDate = raw.match(/^(\d{1,2})[./](\d{1,2})[./](\d{4})(?:\s+(\d{1,2}):(\d{2}))?/);

    if (trDate) {
        const [, day, month, year, hour = '00', minute = '00'] = trDate;
        const date = new Date(Number(year), Number(month) - 1, Number(day), Number(hour), Number(minute));

        return Number.isNaN(date.getTime()) ? null : date;
    }

    const fallback = new Date(raw);

    return Number.isNaN(fallback.getTime()) ? null : fallback;
}

function normalizedColumn(column) {
    return `${column?.key ?? ''} ${column?.label ?? ''}`.toLocaleLowerCase('tr-TR');
}

export function isDateColumn(column) {
    const value = normalizedColumn(column);

    return ['tarih', 'date', 'created_at', 'updated_at', 'son_hareket', 'evrak'].some((token) => value.includes(token));
}

export function isDateTimeColumn(column) {
    const value = normalizedColumn(column);

    return ['saat', 'datetime', 'created_at', 'updated_at'].some((token) => value.includes(token));
}

export function isMoneyColumn(column) {
    const value = normalizedColumn(column);

    return ['tutar', 'fiyat', 'toplam', 'bakiye', 'ciro', 'borç', 'borc', 'alacak', 'iskonto', 'kdv', 'risk'].some((token) => value.includes(token));
}

export function isNumberColumn(column) {
    const value = normalizedColumn(column);

    return isMoneyColumn(column) || ['miktar', 'adet', 'kalan', 'teslim', 'gelen', 'qty', 'quantity'].some((token) => value.includes(token));
}

export function formatMoney(value) {
    const parsed = parseNumeric(value);

    return `${moneyFormatter.format(parsed ?? 0)} TL`;
}

export function formatMoneyTR(value) {
    return formatMoney(value);
}

export function formatNumber(value) {
    const parsed = parseNumeric(value);

    return parsed === null ? '-' : numberFormatter.format(parsed);
}

export function formatNumberTR(value) {
    return formatNumber(value);
}

export function formatPercent(value) {
    const parsed = parseNumeric(value);

    return parsed === null ? '-' : `%${new Intl.NumberFormat('tr-TR', { maximumFractionDigits: 2 }).format(parsed)}`;
}

export function formatDate(value) {
    return formatDateTR(value);
}

export function formatDateTR(value) {
    const date = parseDateValue(value);

    return date ? dateFormatter.format(date) : '-';
}

export function formatDateTimeTR(value) {
    const date = parseDateValue(value);

    return date ? dateTimeFormatter.format(date) : '-';
}

export function numericValue(value) {
    return parseNumeric(value) ?? 0;
}

export function formatCell(value, column = null) {
    if (value === null || value === undefined || value === '') {
        return '-';
    }

    if (column && isDateColumn(column)) {
        return isDateTimeColumn(column) ? formatDateTimeTR(value) : formatDateTR(value);
    }

    if (column && isMoneyColumn(column)) {
        return formatMoney(value);
    }

    if (column && isNumberColumn(column)) {
        return formatNumber(value);
    }

    if (typeof value === 'number') {
        return formatNumber(value);
    }

    if (typeof value === 'object') {
        return '-';
    }

    return String(value);
}
