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
        let message = detail;

        try {
            const parsed = JSON.parse(detail);
            message = parsed.message || detail;
        } catch {
            message = detail;
        }

        throw new Error(message || `Request failed with ${response.status}`);
    }

    return response.json();
}
