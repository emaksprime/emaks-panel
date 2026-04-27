export function formatMoney(value) {
    return `${new Intl.NumberFormat('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(value || 0))} TL`;
}

export function formatNumber(value) {
    return new Intl.NumberFormat('tr-TR', { maximumFractionDigits: 2 }).format(Number(value || 0));
}

export function formatPercent(value) {
    return `%${new Intl.NumberFormat('tr-TR', { maximumFractionDigits: 2 }).format(Number(value || 0))}`;
}

export function formatDate(value) {
    if (!value) {
        return '-';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? String(value) : new Intl.DateTimeFormat('tr-TR').format(date);
}

export function formatCell(value) {
    if (value === null || value === undefined || value === '') {
        return '-';
    }

    if (typeof value === 'number') {
        return formatNumber(value);
    }

    if (typeof value === 'object') {
        return JSON.stringify(value);
    }

    return String(value);
}
