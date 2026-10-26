// The codes of the API errors (Carbure\Lib\ApiError, "error_code" of an error answer) and
// their translations (apiErrors.<code> of portal/i18n/<locale>.json)
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = fileURLToPath(new URL('../..', import.meta.url));

/**
 * PHP files of a directory, recursively
 * @param {string} dir - Directory (relative to the project)
 * @returns {string[]} Paths relative to the project
 */
function phpFiles(dir) {
    return readdirSync(join(ROOT, dir)).flatMap(name => {
        const path = join(dir, name);
        if (statSync(join(ROOT, path)).isDirectory()) {
            return phpFiles(path);
        }
        return name.endsWith('.php') ? [path] : [];
    });
}

/** Sources of the API */
const SOURCES = phpFiles('src').map(path => ({ path, code: readFileSync(join(ROOT, path), 'utf8') }));

/** Languages of the portal and their texts */
const LOCALES = readdirSync(join(ROOT, 'portal/i18n')).filter(name => name.endsWith('.json')).map(name => name.slice(0, -5));
const TEXTS = Object.fromEntries(LOCALES.map(locale => [locale, JSON.parse(readFileSync(join(ROOT, `portal/i18n/${locale}.json`), 'utf8'))]));

/**
 * Calls of ApiError whose code is not a literal, with where their codes are written:
 * the code of the error caught (Insight::test()), the causes of a refused bank (Bank::REFUSALS)
 */
const DYNAMIC_CALLS = ["$e->getErrorCode()", '$code'];

/**
 * The codes thrown by the API: the literal code of each new ApiError(message, status, 'code'),
 * the codes of Bank::REFUSALS and the 'error_code' => 'code' written in a response
 * @returns {{codes: Set<string>, unknown: string[]}} The codes, and the calls whose code is not found
 */
function thrownCodes() {
    const codes = new Set();
    const unknown = [];
    for (const { path, code } of SOURCES) {
        for (const [call] of code.matchAll(/new ApiError\([\s\S]*?\);?\n/g)) {
            const literal = call.match(/,\s*\d{3},\s*'(\w+)'/);
            if (literal) {
                codes.add(literal[1]);
            } else if (!DYNAMIC_CALLS.some(name => call.includes(`, ${name}`))) {
                unknown.push(`${path}: ${call.trim()}`);
            }
        }
        for (const [, name] of code.matchAll(/'error_code' => '(\w+)'/g)) {
            codes.add(name);
        }
        if (path.endsWith('Bank.php')) {
            const refusals = code.match(/const REFUSALS = \[([\s\S]*?)\];/)[1];
            for (const [, name] of refusals.matchAll(/=> \['(\w+)',/g)) {
                codes.add(name);
            }
        }
    }
    return { codes, unknown };
}

test('the API throws ApiError with literal codes in snake_case', () => {
    const { codes, unknown } = thrownCodes();
    assert.deepEqual(unknown, [], 'calls of ApiError without a literal code: write it, or list the call in DYNAMIC_CALLS');
    assert.ok(codes.size >= 80, `${codes.size} codes`);
    assert.ok(codes.has('keyword_exists') && codes.has('bank_credentials_refused') && codes.has('insight_query_failed'));
    for (const code of codes) {
        assert.match(code, /^[a-z]+(_[a-z0-9]+)*$/, code);
    }
});

test('every code thrown by the API is translated in every language, and no translation is left over', () => {
    const { codes } = thrownCodes();
    for (const [locale, texts] of Object.entries(TEXTS)) {
        const keys = Object.keys(texts.apiErrors || {});
        assert.deepEqual([...codes].filter(code => !keys.includes(code)).sort(), [], `codes without apiErrors.<code> in portal/i18n/${locale}.json`);
        assert.deepEqual(keys.filter(key => !codes.has(key)).sort(), [], `apiErrors.<code> of portal/i18n/${locale}.json thrown nowhere`);
    }
});

test('the values placed in a translation are the params of its error', () => {
    // The params of each code, by the names written in the call ('name' => …)
    const params = new Map();
    for (const { code } of SOURCES) {
        for (const [call] of code.matchAll(/new ApiError\([\s\S]*?\);?\n/g)) {
            const literal = call.match(/,\s*\d{3},\s*'(\w+)'/);
            if (literal) {
                const names = [...call.matchAll(/'(\w+)' =>/g)].map(m => m[1]);
                params.set(literal[1], new Set([...(params.get(literal[1]) || []), ...names]));
            }
        }
    }
    // Codes whose values are set elsewhere: the causes of a refused bank, a refusal of the database
    params.set('bank_credentials_refused', new Set(['module', 'cause']));
    params.set('bank_blocked', new Set(['module', 'cause']));
    params.set('insight_query_failed', new Set(['cause']));
    for (const [code, text] of Object.entries(TEXTS.en.apiErrors)) {
        const placed = [...text.matchAll(/\{(\w+)\}/g)].map(m => m[1]);
        assert.deepEqual(placed.filter(name => !(params.get(code) || new Set()).has(name)), [], `{placeholders} of apiErrors.${code} not sent by the API`);
    }
});
