export async function apiRequest(path, options = {}) {
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    const response = await fetch(path, {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            ...(token ? { 'X-CSRF-TOKEN': token } : {}),
            ...(options.headers ?? {}),
        },
        ...options,
    });

    if (!response.ok) {
        const detail = await response.text();
        let message = 'Veri alınamadı. Lütfen tekrar deneyin.';

        try {
            const parsed = JSON.parse(detail);
            if (response.status < 500) {
                message = parsed.message || parsed.error || message;
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
