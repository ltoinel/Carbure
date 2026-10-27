// Split of a transaction across several categories (portal/modules/splitModule.js)
import { mockFetch, json } from './setup.mjs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createSplitModule, splitRemainder, toCents, withSplitParts, MAX_SPLIT_PARTS } from '../../portal/modules/splitModule.js';
import { createTransactionModule } from '../../portal/modules/transactionModule.js';
import { createApiService } from '../../portal/services/apiService.js';

const ORIGIN = { id: 1, label: 'CB CARREFOUR', category: 2, amount: '-62.40', pointed: 0, split_of: null };
const PART = { id: 9, label: 'CB CARREFOUR', category: 3, amount: '-20.00', pointed: 0, split_of: 1 };
const OTHER = { id: 2, label: 'VIR SALAIRE', category: 5, amount: '3200.00', pointed: 1, split_of: null };

/** A split module bound to a fake component, with the transactions of the month */
function splitContext(api = {}, transactions = [ORIGIN, OTHER, PART].map(t => ({ ...t }))) {
    const module = createSplitModule(() => api);
    const context = {
        ...module.data(),
        transactions, searchResults: [], isSearching: false,
        selectedMonth: 10, selectedYear: 2026,
        toasts: [], reloads: 0,
        t: (key) => key,
        showToast(text) { this.toasts.push(text); },
        apiErrorText: error => error.message,
        loadTransactions() { this.reloads++; },
        runSearch() {},
    };
    Object.assign(context, module.methods);
    for (const [name, compute] of Object.entries(module.computed)) {
        Object.defineProperty(context, name, { get: () => compute.call(context) });
    }
    return context;
}

test('toCents reads the amounts typed, with a comma or a dot', () => {
    assert.equal(toCents('20,5'), 2050);
    assert.equal(toCents('-0.1'), -10);
    assert.equal(toCents(19.99), 1999);
    assert.equal(toCents(''), 0);
    assert.equal(toCents('abc'), 0);
});

test('splitRemainder gives the parts the sign of the transaction', () => {
    assert.equal(splitRemainder(-82.4, [{ amount: '20' }]), -6240);
    assert.equal(splitRemainder(-82.4, [{ amount: '20' }, { amount: '-2.40' }]), -6000);
    assert.equal(splitRemainder(100, [{ amount: '40' }, { amount: '' }]), 6000);
    assert.equal(splitRemainder(-10, [{ amount: '15' }]), 500);
});

test('withSplitParts lists the parts right after their transaction', () => {
    assert.deepEqual(withSplitParts([PART, OTHER, ORIGIN]).map(t => t.id), [2, 1, 9]);
    // A part whose transaction is not listed stays in its place
    assert.deepEqual(withSplitParts([OTHER, PART]).map(t => t.id), [2, 9]);
    const list = [ORIGIN, OTHER];
    assert.equal(withSplitParts(list), list);
});

test('the transaction list shows the parts after their transaction', () => {
    const transactions = createTransactionModule(() => null);
    const shown = transactions.computed.displayedTransactions.call({
        transactions: [PART, OTHER, ORIGIN], searchResults: [], isSearching: false, transactionFilter: 'all'
    });
    assert.deepEqual(shown.map(t => t.id), [2, 1, 9]);
});

test('the amount of the bank adds the parts back', () => {
    const context = splitContext();
    assert.equal(context.transactionBankAmount(context.transactions[0]), -82.4);
    assert.deepEqual(context.transactionParts(context.transactions[0]).map(t => t.id), [9]);
    assert.equal(context.transactionBankAmount(context.transactions[1]), 3200);
});

test('openSplit starts from the parts of the transaction, also from one of its parts', () => {
    const context = splitContext();
    context.transactionDetail = context.transactions[2];
    context.openSplit();

    assert.equal(context.transactionDetail, null);
    assert.equal(context.splitTransaction.id, 1);
    assert.deepEqual(context.splitParts, [{ category: 3, amount: '20.00' }]);
    assert.equal(context.splitBankAmount, -82.4);
    assert.equal(context.splitRest, -62.4);
    assert.equal(context.splitValid, true);

    // Not split yet: one empty part
    context.transactionDetail = context.transactions[1];
    context.openSplit();
    assert.deepEqual(context.splitParts, [{ category: '', amount: '' }]);
    assert.equal(context.splitValid, false);
});

test('the parts are valid only with a category, an amount and a rest of the sign of the transaction', () => {
    const context = splitContext();
    context.splitTransaction = context.transactions[0];
    context.splitParts = [{ category: 1, amount: '82.40' }];
    assert.equal(context.splitValid, false);
    context.splitParts = [{ category: '', amount: '10' }];
    assert.equal(context.splitValid, false);
    context.splitParts = [{ category: 0, amount: '10' }];
    assert.equal(context.splitValid, true);

    context.splitParts = [];
    for (let i = 0; i < MAX_SPLIT_PARTS + 2; i++) {
        context.addSplitPart();
    }
    assert.equal(context.splitParts.length, MAX_SPLIT_PARTS);
    assert.equal(context.splitFull, true);
    context.removeSplitPart(0);
    assert.equal(context.splitFull, false);
});

test('saveSplit sends the parts with the sign of the transaction, then reloads', async () => {
    const sent = [];
    const context = splitContext({ splitTransaction: async (id, parts) => { sent.push([id, parts]); return []; } });
    context.splitTransaction = context.transactions[0];
    context.splitParts = [{ category: 3, amount: '20' }, { category: '1', amount: '5,5' }];

    await context.saveSplit();
    assert.deepEqual(sent, [[1, [{ category: 3, amount: -20 }, { category: 1, amount: -5.5 }]]]);
    assert.equal(context.splitTransaction, null);
    assert.deepEqual(context.toasts, ['splitSaved']);
    assert.equal(context.reloads, 1);

    context.splitTransaction = context.transactions[0];
    await context.cancelSplit();
    assert.deepEqual(sent[1], [1, []]);
    assert.deepEqual(context.toasts, ['splitSaved', 'splitCancelled']);
});

test('a refused split keeps the modal open and shows the error', async () => {
    const context = splitContext({ splitTransaction: async () => { throw new Error('The parts must leave a rest'); } });
    context.splitTransaction = context.transactions[0];
    context.splitParts = [{ category: 3, amount: '20' }];

    await context.saveSplit();
    assert.equal(context.splitTransaction.id, 1);
    assert.deepEqual(context.toasts, ['The parts must leave a rest']);
    assert.equal(context.savingSplit, false);
});

test('checking a split transaction checks its parts', async () => {
    const transactions = createTransactionModule(() => ({ setTransactionPointed: async () => {} }));
    const list = [ORIGIN, PART, OTHER].map(t => ({ ...t }));
    const context = { transactions: list, showToast() {}, apiErrorText: e => e.message };
    await transactions.methods.toggleTransactionPointed.call(context, list[0]);
    assert.deepEqual(list.map(t => t.pointed), [1, 1, 1]);
});

test('splitTransaction sends PUT /transaction/split', async () => {
    const calls = mockFetch(() => json([{ id: 1 }, { id: 9, split_of: 1 }]));
    const api = createApiService('https://carbure.example.com/api', 't');

    assert.deepEqual(await api.splitTransaction(1, [{ category: 3, amount: -20 }]), [{ id: 1 }, { id: 9, split_of: 1 }]);
    assert.equal(calls[0].url, 'https://carbure.example.com/api/transaction/split');
    assert.equal(calls[0].options.method, 'PUT');
    assert.deepEqual(JSON.parse(calls[0].options.body), { id: 1, parts: [{ category: 3, amount: -20 }] });
});
