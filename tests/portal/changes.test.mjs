// modules/changesModule.js: reload of the open tab when its data changed on the server
import { mockFetch, json } from './setup.mjs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createApiService } from '../../portal/services/apiService.js';
import { afterOwnWrite, createChangesModule, movedKinds, parseChangesHeader, TAB_KINDS } from '../../portal/modules/changesModule.js';

const BASE = 'https://carbure.example.com/api';

/** document of the browser: no modal open by default, the page visible */
function fakeDocument({ modal = false } = {}) {
    const listeners = {};
    globalThis.document = {
        hidden: false,
        querySelector: selector => (modal && selector === '.modal-overlay' ? {} : null),
        addEventListener: (type, listener) => { listeners[type] = listener; },
        removeEventListener: type => { delete listeners[type]; }
    };
    return listeners;
}

/** Answer of GET /changes */
function changes(versions) {
    return Object.fromEntries(Object.entries(versions).map(([kind, version]) => [kind, { version, updated_at: null }]));
}

/** A component with the changes mixin, recording the reloads */
function component(api, activeTab = 'budget') {
    const mixin = createChangesModule(typeof api === 'function' ? api : () => api);
    const vm = { isAuthenticated: true, activeTab, reloads: [], periods: 0 };
    vm.refreshCurrentTab = () => vm.reloads.push(vm.activeTab);
    vm.loadPeriods = () => vm.periods++;
    for (const [name, method] of Object.entries(mixin.methods)) {
        vm[name] = method.bind(vm);
    }
    return vm;
}

test('the header of a write gives the versions it bumped', () => {
    assert.deepEqual(parseChangesHeader('budgets=1835, transactions=1836'), { budgets: 1835, transactions: 1836 });
    assert.deepEqual(parseChangesHeader(null), {});
    assert.deepEqual(parseChangesHeader('budgets=x, =3, accounts'), {});
});

test('only the kinds whose version moved are reloaded', () => {
    assert.deepEqual(movedKinds({ budgets: 3, transactions: 8 }, { budgets: 4, transactions: 8 }), ['budgets']);
    assert.deepEqual(movedKinds({ budgets: 3 }, { budgets: 3, settings: 1 }), ['settings']);
});

test('a write of the portal is up to date, unless someone else wrote meanwhile', () => {
    assert.deepEqual(afterOwnWrite({ budgets: 3, transactions: 8 }, { budgets: 4, transactions: 10 }),
        { budgets: 4, transactions: 8 });
});

test('the insights and the trends follow the transactions and the budgets', () => {
    for (const tab of ['insights', 'trends']) {
        assert.ok(TAB_KINDS[tab].includes('transactions') && TAB_KINDS[tab].includes('budgets'), tab);
    }
});

test('the open tab is reloaded when its data changed, not for another kind', async () => {
    const listeners = fakeDocument();
    let versions = { transactions: 1, budgets: 1, settings: 1 };
    const calls = mockFetch(() => json(changes(versions)));
    const vm = component(createApiService(BASE, 't'));

    await vm.startChangeWatch();
    assert.equal(calls[0].url, `${BASE}/changes`);
    assert.deepEqual(vm.reloads, []);

    // The settings changed: the budget tab does not show them
    versions = { ...versions, settings: 2 };
    await vm.checkChanges();
    assert.deepEqual(vm.reloads, []);

    // A synchronization brought transactions: the budgets and the periods are reloaded
    versions = { ...versions, transactions: 2 };
    await vm.checkChanges();
    assert.deepEqual(vm.reloads, ['budget']);
    assert.equal(vm.periods, 1);

    // Back to the page: checked at once
    versions = { ...versions, budgets: 2 };
    await listeners.visibilitychange();
    await new Promise(resolve => setImmediate(resolve));
    assert.deepEqual(vm.reloads, ['budget', 'budget']);
    vm.stopChangeWatch();
    assert.equal(listeners.visibilitychange, undefined);
});

test('the own writes of the portal reload nothing more', async () => {
    fakeDocument();
    let versions = { budgets: 1 };
    let api = null;
    const vm = component(() => api);
    api = createApiService(BASE, 't', null, null, written => vm.ownChanges(written));
    mockFetch(url => (url.endsWith('/changes') ? json(changes(versions))
        : new Response('true', { headers: { 'X-Carbure-Changes': 'budgets=2' } })));

    await vm.checkChanges();
    versions = { budgets: 2 };
    await api.updateBudget(3, 10, 2026, 120);
    await vm.checkChanges();
    assert.deepEqual(vm.reloads, []);
});

test('not while a modal is open, nor against a server without markers', async () => {
    fakeDocument({ modal: true });
    const calls = mockFetch(() => json(changes({ budgets: 1 })));
    const vm = component(createApiService(BASE, 't'));
    await vm.checkChanges();
    assert.equal(calls.length, 0);

    const listeners = fakeDocument();
    mockFetch(() => json({ error: 'Route not found: GET /changes' }, 404));
    await vm.startChangeWatch();
    assert.equal(listeners.visibilitychange, undefined);
});
