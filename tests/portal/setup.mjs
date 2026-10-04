// Browser globals used by the portal modules, for the unit tests run by Node.
// Import it first: `import './setup.mjs';`

/** CSS.supports(): the named colors of CSS used by the categories */
globalThis.CSS = { supports: (property, value) => property === 'color' && ['black', 'white', 'tomato', 'gold'].includes(value) };

/** Vue.reactive(): plain objects are enough without rendering */
globalThis.Vue = { reactive: value => value };

/** window.fetch is replaced by each test (see mockFetch) */
globalThis.window = { location: { search: '', href: 'https://carbure.example.com/portal/' } };

/**
 * Replaces window.fetch by a function answering from a handler, and records the calls
 * @param {Function} handler - (url, options) => Response | Promise<Response>
 * @returns {Array<{url: string, options: Object}>} The calls
 */
export function mockFetch(handler) {
    const calls = [];
    window.fetch = async (url, options = {}) => {
        calls.push({ url, options });
        if (options.signal?.aborted) {
            throw new DOMException('The operation was aborted.', 'AbortError');
        }
        return handler(url, options);
    };
    return calls;
}

/**
 * JSON response
 * @param {any} body
 * @param {number} status
 * @returns {Response}
 */
export function json(body, status = 200) {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}
