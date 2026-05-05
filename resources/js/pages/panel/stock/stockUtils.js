import { numericValue } from '../../../components/primecrm/format.js';

export const DEFAULT_STOCK_CATEGORY = 'AKILLI KİLİT';
export const ALL_CATEGORIES = 'Tüm Kategoriler';

const aliases = {
    stockCode: ['stok_kodu', 'sto_kod', 'stock_code', 'Stok Kodu'],
    productName: ['urun_adi', 'stok_adi', 'sto_isim', 'model_adi', 'urunAdi', 'stok_isim', 'Ürün'],
    category: ['kategori', 'kategori_adi', 'stok_kategori_adi', 'sto_kategori_kodu', 'kategori_kodu', 'Kategori'],
    categoryCode: ['kategori_kodu', 'sto_kategori_kodu', 'category_code'],
    modelName: ['model_adi', 'mdl_ismi', 'model', 'Model'],
    quantity: ['miktar', 'toplam_miktar', 'quantity', 'adet', 'Miktar'],
};

export function valueFrom(row, key) {
    const keys = aliases[key] ?? [key];
    const found = keys.find((candidate) => Object.prototype.hasOwnProperty.call(row ?? {}, candidate));

    return found ? row[found] : undefined;
}

export function normalizeSearchText(value) {
    return String(value ?? '')
        .toLocaleLowerCase('tr-TR')
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .replace(/ı/g, 'i')
        .trim();
}

export function stockCode(row) {
    return String(valueFrom(row, 'stockCode') ?? '').trim();
}

export function stockName(row) {
    return String(valueFrom(row, 'productName') ?? '-').trim() || '-';
}

export function stockCategory(row) {
    return String(valueFrom(row, 'category') ?? '').trim();
}

export function stockCategoryCode(row) {
    return String(valueFrom(row, 'categoryCode') ?? '').trim();
}

export function stockCategoryKey(row) {
    return stockCategoryCode(row) || stockCategory(row);
}

export function stockCategoryLabel(row) {
    return stockCategory(row) || stockCategoryCode(row);
}

export function stockModel(row) {
    return String(valueFrom(row, 'modelName') ?? '').trim();
}

export function stockQuantity(row) {
    return numericValue(valueFrom(row, 'quantity'));
}

export function categoryOptions(rows) {
    const options = new Map();

    rows.forEach((row) => {
        const value = stockCategoryKey(row);
        const label = stockCategoryLabel(row);

        if (!value && !label) {
            return;
        }

        const key = normalizeSearchText(value || label);

        if (!options.has(key)) {
            options.set(key, {
                value: value || label,
                label: label || value,
            });
        }
    });

    return [
        { value: ALL_CATEGORIES, label: ALL_CATEGORIES },
        ...Array.from(options.values()).sort((a, b) => a.label.localeCompare(b.label, 'tr-TR')),
    ];
}

export function resolveCategoryFilter(category, options = []) {
    const value = String(category ?? '').trim();

    if (!value || value === ALL_CATEGORIES) {
        return ALL_CATEGORIES;
    }

    const normalizedValue = normalizeSearchText(value);
    const match = options.find((option) => (
        normalizeSearchText(option.value) === normalizedValue
        || normalizeSearchText(option.label) === normalizedValue
    ));

    return match?.value ?? ALL_CATEGORIES;
}

export function criticalSettingFor(row, settings = []) {
    const code = stockCode(row);

    if (!code) {
        return null;
    }

    return settings.find((setting) => {
        const settingCode = String(setting.stock_code ?? setting.stockCode ?? '').trim();

        return settingCode === code && Boolean(setting.active ?? true);
    }) ?? null;
}

export function isCriticalStock(row, settings = []) {
    const setting = criticalSettingFor(row, settings);

    return Boolean(setting) && stockQuantity(row) <= numericValue(setting.threshold_quantity ?? setting.thresholdQuantity);
}

export function decorateStockRows(rows, settings = []) {
    return rows
        .filter((row) => stockQuantity(row) > 0)
        .map((row) => {
            const setting = criticalSettingFor(row, settings);
            const critical = Boolean(setting) && stockQuantity(row) <= numericValue(setting.threshold_quantity ?? setting.thresholdQuantity);

            return {
                ...row,
                stock_code: stockCode(row),
                product_name: stockName(row),
                category_name: stockCategory(row),
                category_code: stockCategoryCode(row),
                model_name: stockModel(row),
                quantity_value: stockQuantity(row),
                criticalSetting: setting,
                criticalThreshold: setting ? numericValue(setting.threshold_quantity ?? setting.thresholdQuantity) : null,
                isCritical: critical,
            };
        });
}

export function filterStockRows(rows, filters = {}) {
    const query = normalizeSearchText(filters.search);
    const category = String(filters.category ?? '').trim();
    const normalizedCategory = normalizeSearchText(category);

    return rows.filter((row) => {
        if (filters.mode === 'critical' && !row.isCritical) {
            return false;
        }

        if (
            category
            && category !== ALL_CATEGORIES
            && ![stockCategoryKey(row), stockCategory(row), stockCategoryCode(row)]
                .some((value) => normalizeSearchText(value) === normalizedCategory)
        ) {
            return false;
        }

        if (!query) {
            return true;
        }

        return [stockCode(row), stockName(row), stockModel(row), stockCategory(row), stockCategoryCode(row)]
            .some((value) => normalizeSearchText(value).includes(query));
    });
}

export function sortStockRows(rows) {
    return [...rows].sort((left, right) => {
        if (left.isCritical !== right.isCritical) {
            return left.isCritical ? -1 : 1;
        }

        return stockName(left).localeCompare(stockName(right), 'tr-TR');
    });
}
