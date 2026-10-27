// Transactions entered by hand on manual accounts (portal/modules/manualModule.js)
import { mockFetch, json } from './setup.mjs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createManualModule, isManual, isoDay, manualPayload } from '../../portal/modules/manualModule.js';
import { accountsByBank, manualAccountsOf } from '../../portal/modules/accountsModule.js';
import { createApiService } from '../../portal/services/apiService.js';

/** A manual module bound to a fake component */
function manualContext(api = {}) {
    const module = createManualModule(() => api);
    const context = {
        ...module.data(),
        selectedMonth: 10, selectedYear: 2026, isAdmin: false, transactionDetail: null,
        toasts: [], reloads: 0, tabs: [],
        t: key => key,
        showToast(text) { this.toasts.push(text); },
        apiErrorText: error => error.message,
        loadTransactions() { this.reloads++; },
        loadPeriods() {},
        setActiveTab(tab) { this.tabs.push(tab); },
    };
    Object.assign(context, module.methods);
    for (const [name, compute] of Object.entries(module.computed)) {
        Object.defineProperty(context, name, { get: () => compute.call(context) });
    }
    return context;
}

test('isManual: a transaction of a manual account, not a part of a split one', () => {
    assert.equal(isManual({ bank_name: 'manual', split_of: null }), true);
    assert.equal(isManual({ bank_name: 'manual', split_of: 4 }), false);
    assert.equal(isManual({ bank_name: 'bnp', split_of: null }), false);
    assert.equal(isManual({ bank_name: null }), false);
    assert.equal(isManual(null), false);
});

test('isoDay gives the local day', () => {
    assert.equal(isoDay(new Date(2026, 0, 5, 23, 30)), '2026-01-05');
});

test('manualPayload gives the amount the sign of the direction', () => {
    const form = { account: '3', date: '2026-10-27', label: ' Marché ', amount: '12,5', direction: 'expense', category: '' };
    assert.deepEqual(manualPayload(form), { account: 3, date: '2026-10-27', label: 'Marché', amount: -12.5 });
    assert.deepEqual(manualPayload({ ...form, direction: 'income', amount: '-20', category: 0 }),
        { account: 3, date: '2026-10-27', label: 'Marché', amount: 20, category: 0 });
    assert.equal(manualPayload({ ...form, amount: 'abc' }).amount === 0, true);
});

test('the manual accounts are listed apart from the banks', () => {
    const accounts = [{ id: 1, bank_name: 'bnp' }, { id: 2, bank_name: 'manual', manual: true }, { id: 3, bank_name: 'old' }];
    const groups = accountsByBank([{ name: 'bnp' }], accounts);
    assert.deepEqual(groups.map(g => g.accounts.map(a => a.id)), [[1], [3]]);
    assert.deepEqual(manualAccountsOf(accounts).map(a => a.id), [2]);
});

test('a new transaction starts today, on the first manual account', async () => {
    const context = manualContext({ fetchAccounts: async () => [{ id: 1, manual: false }, { id: 5, manual: true }, { id: 6, manual: true }] });
    await context.openManualTransaction();

    assert.equal(context.showManualModal, true);
    assert.deepEqual(context.manualAccounts.map(a => a.id), [5, 6]);
    assert.equal(context.manualForm.account, 5);
    assert.equal(context.manualForm.date, isoDay(new Date()));
    assert.equal(context.manualValid, false);
    context.manualForm.label = 'Boulangerie';
    context.manualForm.amount = '4.20';
    assert.equal(context.manualValid, true);
});

test('saves a new transaction, then reloads', async () => {
    const sent = [];
    const context = manualContext({ createTransaction: async t => { sent.push(t); return t; } });
    context.manualForm = { id: null, account: 5, date: '2026-10-27', label: 'Marché', amount: '20', direction: 'expense', category: 3 };
    context.showManualModal = true;

    await context.saveManualTransaction();
    assert.deepEqual(sent, [{ account: 5, date: '2026-10-27', label: 'Marché', amount: -20, category: 3 }]);
    assert.equal(context.showManualModal, false);
    assert.deepEqual(context.toasts, ['manualTransactionAdded']);
    assert.equal(context.reloads, 1);
});

test('changes a transaction entered by hand, from its detail', async () => {
    const sent = [];
    const context = manualContext({ updateTransaction: async t => { sent.push(t); return t; } });
    context.transactionDetail = { id: 9, bank_name: 'manual', date: '2026-10-20', label: 'PAIN', amount: '-4.20' };
    context.editManualTransaction(context.transactionDetail);

    assert.equal(context.transactionDetail, null);
    assert.deepEqual([context.manualForm.amount, context.manualForm.direction], ['4.20', 'expense']);
    assert.equal(context.manualValid, true);
    context.manualForm.amount = '5';
    await context.saveManualTransaction();
    assert.deepEqual(sent, [{ id: 9, date: '2026-10-20', label: 'PAIN', amount: -5 }]);
    assert.deepEqual(context.toasts, ['transactionSaved']);
});

test('a refused transaction keeps the form open with the error', async () => {
    const context = manualContext({ createTransaction: async () => { throw new Error('Invalid date (YYYY-MM-DD)'); } });
    context.manualForm = { id: null, account: 5, date: '2026-10-27', label: 'X', amount: '1', direction: 'expense', category: '' };
    context.showManualModal = true;

    await context.saveManualTransaction();
    assert.equal(context.showManualModal, true);
    assert.deepEqual(context.toasts, ['Invalid date (YYYY-MM-DD)']);
    assert.equal(context.savingManual, false);
});

test('without manual account, an administrator goes to the Accounts tab', () => {
    const context = manualContext();
    context.showManualModal = true;
    context.goToManualAccounts();
    assert.equal(context.showManualModal, false);
    assert.deepEqual(context.tabs, ['accounts']);
});

test('the API of the transactions entered by hand and of the manual accounts', async () => {
    const base = 'https://carbure.example.com/api';
    const calls = mockFetch(() => json({ id: 1 }));
    const api = createApiService(base, 't');

    await api.createTransaction({ account: 5, date: '2026-10-27', label: 'X', amount: -1 });
    await api.updateTransaction({ id: 9, date: '2026-10-27', label: 'X', amount: -2 });
    await api.createManualAccount('Espèces');
    assert.deepEqual(calls.map(c => [c.url, c.options.method]), [
        [`${base}/transaction`, 'POST'], [`${base}/transaction`, 'PUT'], [`${base}/bank/manual`, 'POST']]);
    assert.deepEqual(JSON.parse(calls[2].options.body), { label: 'Espèces', account_number: '' });

    mockFetch(() => json([{ id: 1, manual: true }]));
    assert.deepEqual(await api.fetchAccounts(), [{ id: 1, manual: true }]);
});
