/**
 * Download an export file from the server.
 *
 * Inertia visits cannot deliver files: a non-Inertia response — the CSV/JSON
 * attachment, or the 422 refusal body — is rendered raw in the error frame
 * instead of downloading. The export routes are therefore fetched directly:
 * the file is received as a blob and handed to the browser as a download,
 * and a refusal (beyond the row limit) is read from the JSON body so the
 * caller can show the server's localized message.
 */

export type ExportDownloadResult = { ok: true } | { ok: false; error: string };

/**
 * Fetch the export and hand the file to the browser.
 *
 * Array values (the multi-label filter) serialize as `key[]`, empty values
 * are dropped so a cleared period bound falls back to the server default.
 *
 * @param url Named-route URL of the export.
 * @param params Query params: the page filters plus `format`.
 * @param fallbackError Message shown when the server answer cannot be read.
 */
export async function downloadExport(
    url: string,
    params: Record<string, string | string[]>,
    fallbackError: string,
): Promise<ExportDownloadResult> {
    const query = new URLSearchParams();

    for (const [key, value] of Object.entries(params)) {
        if (value === '') {
            continue;
        }

        if (Array.isArray(value)) {
            value.forEach((item) => query.append(`${key}[]`, item));
        } else {
            query.append(key, value);
        }
    }

    let response: Response;

    try {
        // The refusal body is read as JSON: the header pins that contract,
        // because a plain fetch declares `Accept: */*` — the server would
        // then answer a redirect the client cannot read, and the user would
        // receive a garbage file instead of the refusal message.
        response = await fetch(`${url}?${query.toString()}`, {
            headers: { Accept: 'application/json' },
        });
    } catch {
        return { ok: false, error: fallbackError };
    }

    if (!response.ok) {
        const message = await firstValidationError(response);

        return { ok: false, error: message ?? fallbackError };
    }

    const blob = await response.blob();
    const objectUrl = URL.createObjectURL(blob);
    const anchor = document.createElement('a');

    anchor.href = objectUrl;
    anchor.download = filenameFrom(response.headers.get('content-disposition'));
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    URL.revokeObjectURL(objectUrl);

    return { ok: true };
}

/**
 * First message of a validation refusal body (`{ message, errors }`), as
 * written and localized by the server. Anything else — network hiccups,
 * non-JSON bodies — leaves the fallback message in charge.
 */
async function firstValidationError(response: Response): Promise<string | null> {
    try {
        const body: unknown = await response.json();

        if (body !== null && typeof body === 'object' && 'errors' in body) {
            const first = Object.values((body as { errors: Record<string, unknown> }).errors)[0];

            if (Array.isArray(first) && typeof first[0] === 'string') {
                return first[0];
            }
        }
    } catch {
        // Not a JSON body — the fallback message applies.
    }

    return null;
}

/**
 * File name from the Content-Disposition header, UTF-8 form first, then
 * the plain ASCII form, then a generic default.
 */
function filenameFrom(disposition: string | null): string {
    const utf8 = /filename\*=UTF-8''([^;]+)/i.exec(disposition ?? '');

    if (utf8) {
        return decodeURIComponent(utf8[1]);
    }

    return /filename="?([^";]+)"?/i.exec(disposition ?? '')?.[1] ?? 'export.csv';
}
