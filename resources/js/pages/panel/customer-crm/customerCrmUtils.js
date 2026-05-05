function firstValue(row, keys) {
    if (!row || typeof row !== 'object') {
        return undefined;
    }

    for (const key of keys) {
        if (Object.prototype.hasOwnProperty.call(row, key)) {
            const value = row[key];

            if (value === null || value === undefined) {
                continue;
            }

            return value;
        }
    }

    return undefined;
}

function pickAmount(row, keys) {
    const raw = firstValue(row, keys);

    if (raw === undefined || raw === null || String(raw).trim() === '') {
        return null;
    }

    const numeric = Number.parseFloat(String(raw).replace(/\s/g, '').replace(/\./g, '').replace(',', '.'));

    if (Number.isNaN(numeric)) {
        return null;
    }

    return numeric;
}

function normalizeNumericValue(value) {
    const raw = String(value).trim();

    if (raw === '') {
        return null;
    }

    const normalized = raw.replace(/\s/g, '');
    const hasComma = normalized.includes(',');
    const hasDot = normalized.includes('.');
    const hasPercent = normalized.includes('%');

    if (hasPercent) {
        return null;
    }

    if (hasComma && hasDot) {
        const lastComma = normalized.lastIndexOf(',');
        const lastDot = normalized.lastIndexOf('.');

        if (lastComma > lastDot) {
            return Number.parseFloat(normalized.replace(/\./g, '').replace(',', '.'));
        }

        return Number.parseFloat(normalized.replace(/,/g, ''));
    }

    if (hasComma) {
        return Number.parseFloat(normalized.replace(/\./g, '').replace(',', '.'));
    }

    return Number.parseFloat(normalized.replace(/,/g, ''));
}

export function readText(row, keys) {
    const raw = firstValue(row, keys);

    if (raw === undefined || raw === null) {
        return '';
    }

    return String(raw);
}

export function formatMoney(value) {
    const numeric = pickAmount({ value }, ['value']);

    if (numeric === null) {
        return '-';
    }

    return `${new Intl.NumberFormat('tr-TR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(numeric)} TL`;
}

export function formatNumber(value) {
    const numeric = normalizeNumericValue(value);

    if (numeric === null) {
        return '-';
    }

    const digitsAfterSeparator = Number.isInteger(numeric) ? 0 : 2;

    return new Intl.NumberFormat('tr-TR', {
        minimumFractionDigits: digitsAfterSeparator,
        maximumFractionDigits: 2,
    }).format(numeric);
}

export function formatPercentOrNumber(value) {
    if (value === null || value === undefined) {
        return '-';
    }

    const raw = String(value).trim();

    if (raw === '') {
        return '-';
    }

    if (raw.includes('%')) {
        return raw;
    }

    const numeric = normalizeNumericValue(raw);

    if (numeric === null) {
        return '-';
    }

    return `${new Intl.NumberFormat('tr-TR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(numeric)}`;
}

export function readMoney(row, keys) {
    const value = pickAmount(row, keys);

    if (value === null) {
        return '-';
    }

    return `${new Intl.NumberFormat('tr-TR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(value)} TL`;
}

const dateFormatter = new Intl.DateTimeFormat('tr-TR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
});

export function readDate(row, keys) {
    const raw = firstValue(row, keys);

    if (raw === undefined || raw === null || String(raw).trim() === '') {
        return '-';
    }

    const date = new Date(String(raw));

    if (Number.isNaN(date.getTime())) {
        return String(raw);
    }

    return dateFormatter.format(date);
}

export function readNumberRaw(row, keys) {
    return pickAmount(row, keys);
}

export function formatDateForInput(value) {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

export function escapeCsv(value) {
    const raw = value === null || value === undefined ? '' : String(value);
    const escaped = raw.replace(/"/g, '""');

    if (/[",\n]/.test(escaped)) {
        return `"${escaped}"`;
    }

    return escaped;
}

export function makeCsvLine(values) {
    return values.map(escapeCsv).join(',');
}
