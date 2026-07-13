const csrfToken = () =>
    document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content') ?? '';

const replaceCsrfToken = (token) => {
    if (!token) {
        return;
    }

    const meta = document.querySelector('meta[name="csrf-token"]');

    if (meta) {
        meta.setAttribute('content', token);
    }
};

const refreshCsrfToken = async () => {
    const response = await fetch(window.location.href, {
        credentials: 'same-origin',
        headers: {
            Accept: 'text/html',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    if (!response.ok) {
        return false;
    }

    const html = await response.text();
    const token =
        html.match(
            /<meta\s+name=["']csrf-token["']\s+content=["']([^"']+)["']/i,
        )?.[1] ??
        html.match(
            /<meta\s+content=["']([^"']+)["']\s+name=["']csrf-token["']/i,
        )?.[1] ??
        '';

    if (!token) {
        return false;
    }

    replaceCsrfToken(token);

    return true;
};

const requestOptions = (options = {}) => {
    const token = csrfToken();
    const hasFormDataBody =
        typeof FormData !== 'undefined' && options.body instanceof FormData;

    return {
        credentials: 'same-origin',
        ...options,
        headers: {
            Accept: 'application/json',
            ...(!hasFormDataBody
                ? { 'Content-Type': 'application/json' }
                : {}),
            ...(token ? { 'X-CSRF-TOKEN': token } : {}),
            ...(options.headers ?? {}),
        },
    };
};

export async function apiRequest(path, options = {}) {
    let response = await fetch(path, requestOptions(options));

    if (response.status === 419 && (await refreshCsrfToken())) {
        response = await fetch(path, requestOptions(options));
    }

    if (!response.ok) {
        const detail = await response.text();
        let message = 'Veri alınamadı. Lütfen tekrar deneyin.';

        try {
            const parsed = JSON.parse(detail);
            const firstValidationError = parsed.errors
                ? Object.values(parsed.errors).flat()[0]
                : null;

            if (response.status < 500) {
                message =
                    firstValidationError ||
                    parsed.message ||
                    parsed.error ||
                    message;
            }
        } catch {
            // Keep raw Cloudflare/proxy HTML or JSON-like text out of the UI.
        }

        const error = new Error(message);
        error.status = response.status;
        error.detail = detail;

        throw error;
    }

    return response.json();
}
