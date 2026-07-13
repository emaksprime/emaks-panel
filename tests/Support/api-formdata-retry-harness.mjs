import assert from 'node:assert/strict';
import process from 'node:process';

class TestFormData {
    #entries = [];

    append(name, value) {
        this.#entries.push([name, value]);
    }

    entries() {
        return this.#entries[Symbol.iterator]();
    }
}

class TestResponse {
    constructor(status, body) {
        this.status = status;
        this.ok = status >= 200 && status < 300;
        this.body = body;
    }

    async json() {
        if (typeof this.body === 'string') {
            return JSON.parse(this.body);
        }

        return this.body;
    }

    async text() {
        return typeof this.body === 'string'
            ? this.body
            : JSON.stringify(this.body);
    }
}

const pageUrl = 'https://panel.test/technical-service';

globalThis.FormData = TestFormData;
globalThis.window = { location: { href: pageUrl } };

const apiModuleUrl = new URL('../../resources/js/lib/api.js', import.meta.url);
apiModuleUrl.searchParams.set('formdata-retry-harness', String(Date.now()));

const { apiRequest } = await import(apiModuleUrl.href);

function response(status, body) {
    return new TestResponse(status, body);
}

function uploadFormData() {
    const body = new TestFormData();
    body.append('category', 'before');
    body.append('file', 'controlled-test-image');

    return body;
}

function installEnvironment(handler, initialToken = 'csrf-before') {
    let token = initialToken;
    const calls = [];
    const meta = {
        getAttribute(attribute) {
            return attribute === 'content' ? token : null;
        },
        setAttribute(attribute, value) {
            if (attribute === 'content') {
                token = value;
            }
        },
    };

    globalThis.document = {
        querySelector(selector) {
            return selector === 'meta[name="csrf-token"]' ? meta : null;
        },
    };
    globalThis.fetch = async (url, options = {}) => {
        const call = {
            body: options.body,
            headers: { ...(options.headers ?? {}) },
            method: options.method ?? 'GET',
            url: String(url),
        };
        calls.push(call);

        return handler(call, calls.length);
    };

    return {
        calls,
        token: () => token,
    };
}

async function captureError(callback) {
    try {
        await callback();
    } catch (error) {
        return error;
    }

    assert.fail('Expected apiRequest to reject.');
}

function assertFormDataRequest(call, expectedBody) {
    assert.strictEqual(call.body, expectedBody);
    assert.equal(call.method, 'POST');
    assert.equal(call.headers['Content-Type'], undefined);
}

async function formdata419RefreshRetryOnce() {
    const body = uploadFormData();
    let uploadAttempts = 0;
    let refreshAttempts = 0;
    const environment = installEnvironment((call) => {
        if (call.url === pageUrl) {
            refreshAttempts += 1;

            return response(
                200,
                '<meta name="csrf-token" content="csrf-after">',
            );
        }

        uploadAttempts += 1;

        return uploadAttempts === 1
            ? response(419, { message: 'CSRF token mismatch.' })
            : response(201, { id: 41, uploaded: true });
    });

    const result = await apiRequest('/api/partner/service-jobs/41/photos', {
        body,
        method: 'POST',
    });

    assert.deepEqual(result, { id: 41, uploaded: true });
    assert.equal(uploadAttempts, 2);
    assert.equal(refreshAttempts, 1);
    assert.equal(environment.calls.length, 3);
    assert.equal(environment.calls[0].headers['X-CSRF-TOKEN'], 'csrf-before');
    assert.equal(environment.calls[2].headers['X-CSRF-TOKEN'], 'csrf-after');
    assert.equal(environment.token(), 'csrf-after');
    assertFormDataRequest(environment.calls[0], body);
    assertFormDataRequest(environment.calls[2], body);

    const failedRefreshBody = uploadFormData();
    let failedRefreshUploadAttempts = 0;
    let failedRefreshAttempts = 0;
    const failedRefreshEnvironment = installEnvironment((call) => {
        if (call.url === pageUrl) {
            failedRefreshAttempts += 1;

            return response(503, 'CSRF refresh unavailable.');
        }

        failedRefreshUploadAttempts += 1;

        return response(419, { message: 'CSRF token mismatch.' });
    });

    const failedRefreshError = await captureError(() =>
        apiRequest('/api/partner/service-jobs/42/photos', {
            body: failedRefreshBody,
            method: 'POST',
        }),
    );

    assert.equal(failedRefreshError.status, 419);
    assert.equal(failedRefreshUploadAttempts, 1);
    assert.equal(failedRefreshAttempts, 1);
    assert.equal(failedRefreshEnvironment.calls.length, 2);
    assertFormDataRequest(failedRefreshEnvironment.calls[0], failedRefreshBody);

    return {
        failed_refresh_attempts: failedRefreshAttempts,
        failed_refresh_upload_attempts: failedRefreshUploadAttempts,
        refresh_attempts: refreshAttempts,
        upload_attempts: uploadAttempts,
    };
}

async function uploadRetryDoesNotDuplicateRow() {
    const body = uploadFormData();
    const createdRows = [];
    let uploadAttempts = 0;
    let refreshAttempts = 0;
    const environment = installEnvironment((call) => {
        if (call.url === pageUrl) {
            refreshAttempts += 1;

            return response(
                200,
                '<meta content="csrf-after" name="csrf-token">',
            );
        }

        uploadAttempts += 1;

        if (uploadAttempts === 1) {
            return response(419, { message: 'CSRF token mismatch.' });
        }

        createdRows.push({ id: 73, request_id: 224 });

        return response(201, createdRows[0]);
    });

    const result = await apiRequest('/api/partner/service-jobs/224/photos', {
        body,
        method: 'POST',
    });

    assert.deepEqual(result, { id: 73, request_id: 224 });
    assert.equal(uploadAttempts, 2);
    assert.equal(refreshAttempts, 1);
    assert.equal(createdRows.length, 1);
    assert.strictEqual(environment.calls[0].body, environment.calls[2].body);
    assert.deepEqual([...body.entries()], [
        ['category', 'before'],
        ['file', 'controlled-test-image'],
    ]);
    assertFormDataRequest(environment.calls[0], body);
    assertFormDataRequest(environment.calls[2], body);

    return {
        created_rows: createdRows.length,
        refresh_attempts: refreshAttempts,
        upload_attempts: uploadAttempts,
    };
}

async function non419UploadErrorNotRetried() {
    const body = uploadFormData();
    let uploadAttempts = 0;
    let refreshAttempts = 0;
    const environment = installEnvironment((call) => {
        if (call.url === pageUrl) {
            refreshAttempts += 1;

            return response(200, '<meta name="csrf-token" content="unused">');
        }

        uploadAttempts += 1;

        return response(422, {
            errors: { file: ['Dosya yükleme kurallarını karşılamıyor.'] },
            message: 'Validation failed.',
        });
    });

    const error = await captureError(() =>
        apiRequest('/api/partner/service-jobs/224/photos', {
            body,
            method: 'POST',
        }),
    );

    assert.equal(error.status, 422);
    assert.equal(error.message, 'Dosya yükleme kurallarını karşılamıyor.');
    assert.equal(uploadAttempts, 1);
    assert.equal(refreshAttempts, 0);
    assert.equal(environment.calls.length, 1);
    assertFormDataRequest(environment.calls[0], body);

    return {
        error_status: error.status,
        refresh_attempts: refreshAttempts,
        upload_attempts: uploadAttempts,
    };
}

async function retryFailureReturnsRealError() {
    const body = uploadFormData();
    const retryErrorBody = {
        errors: { file: ['Dosya ikinci denemede reddedildi.'] },
        message: 'Validation failed after CSRF refresh.',
    };
    let uploadAttempts = 0;
    let refreshAttempts = 0;
    const environment = installEnvironment((call) => {
        if (call.url === pageUrl) {
            refreshAttempts += 1;

            return response(
                200,
                '<meta name="csrf-token" content="csrf-after">',
            );
        }

        uploadAttempts += 1;

        return uploadAttempts === 1
            ? response(419, { message: 'CSRF token mismatch.' })
            : response(422, retryErrorBody);
    });

    const error = await captureError(() =>
        apiRequest('/api/partner/service-jobs/224/photos', {
            body,
            method: 'POST',
        }),
    );

    assert.equal(error.status, 422);
    assert.equal(error.message, 'Dosya ikinci denemede reddedildi.');
    assert.equal(error.detail, JSON.stringify(retryErrorBody));
    assert.equal(uploadAttempts, 2);
    assert.equal(refreshAttempts, 1);
    assert.equal(environment.calls.length, 3);
    assertFormDataRequest(environment.calls[0], body);
    assertFormDataRequest(environment.calls[2], body);

    return {
        error_status: error.status,
        refresh_attempts: refreshAttempts,
        upload_attempts: uploadAttempts,
    };
}

const scenarios = {
    formdata_419_refresh_retry_once: formdata419RefreshRetryOnce,
    non_419_upload_error_not_retried: non419UploadErrorNotRetried,
    retry_failure_returns_real_error: retryFailureReturnsRealError,
    upload_retry_does_not_duplicate_row: uploadRetryDoesNotDuplicateRow,
};
const scenario = process.argv[2];

if (!scenario || !scenarios[scenario]) {
    console.error(`Unknown FormData retry scenario: ${scenario ?? '(missing)'}`);
    process.exitCode = 64;
} else {
    try {
        const facts = await scenarios[scenario]();
        console.log(JSON.stringify({ facts, ok: true, scenario }));
    } catch (error) {
        console.error(error?.stack ?? String(error));
        process.exitCode = 1;
    }
}
