export type ClipboardCopyStatus = 'copied' | 'manual';

export type ClipboardCopyResult = {
    status: ClipboardCopyStatus;
    copied: boolean;
    manualCopyRequired: boolean;
    text: string;
};

function copyTextWithTextarea(text: string): boolean {
    if (typeof document === 'undefined' || !document.body) {
        return false;
    }

    const textarea = document.createElement('textarea');

    try {
        textarea.value = text;
        textarea.setAttribute('readonly', 'true');
        textarea.style.position = 'fixed';
        textarea.style.left = '0';
        textarea.style.top = '0';
        textarea.style.width = '1px';
        textarea.style.height = '1px';
        textarea.style.padding = '0';
        textarea.style.border = '0';
        textarea.style.opacity = '0';
        textarea.style.pointerEvents = 'none';
        document.body.appendChild(textarea);
        textarea.focus();
        textarea.select();
        textarea.setSelectionRange(0, textarea.value.length);

        return document.execCommand('copy');
    } catch {
        return false;
    } finally {
        textarea.remove();
    }
}

async function clipboardMatchesText(text: string): Promise<boolean | null> {
    try {
        if (
            typeof navigator === 'undefined' ||
            !navigator.clipboard?.readText
        ) {
            return null;
        }

        return (await navigator.clipboard.readText()) === text;
    } catch {
        return null;
    }
}

async function verifiedCopyResult(
    copied: boolean,
    text: string,
): Promise<boolean> {
    if (!copied) {
        return false;
    }

    const verified = await clipboardMatchesText(text);

    return verified ?? true;
}

export async function copyTextToClipboard(
    value: string,
): Promise<ClipboardCopyResult> {
    const text = String(value ?? '');

    try {
        if (
            typeof navigator !== 'undefined' &&
            navigator.clipboard?.writeText
        ) {
            await navigator.clipboard.writeText(text);

            if (await verifiedCopyResult(true, text)) {
                return {
                    status: 'copied',
                    copied: true,
                    manualCopyRequired: false,
                    text,
                };
            }
        }
    } catch {
        // Fall through to textarea fallback for denied permissions, HTTP contexts, and focus-trap edge cases.
    }

    if (await verifiedCopyResult(copyTextWithTextarea(text), text)) {
        return {
            status: 'copied',
            copied: true,
            manualCopyRequired: false,
            text,
        };
    }

    return {
        status: 'manual',
        copied: false,
        manualCopyRequired: true,
        text,
    };
}
