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
        throw new Error(detail || `Request failed with ${response.status}`);
    }

    return response.json();
}
