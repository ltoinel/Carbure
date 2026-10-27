// Tags of a project limited in time (portal/modules/tagsModule.js)
import { mockFetch, json } from './setup.mjs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createTagsModule, cumulativePath, tagChanges, tagName, tagStatus, MAX_TAG_SELECTION } from '../../portal/modules/tagsModule.js';
import { createApiService } from '../../portal/services/apiService.js';

const TAGS = [
    { id: 1, name: 'voyage', color: 'green', start_date: '2026-07-01', end_date: null, active: true, count: 2, total: -120 },
    { id: 2, name: 'ete-2020', color: 'orange', start_date: '2020-06-01', end_date: '2020-08-31', active: false, count: 0, total: 0 },
    { id: 3, name: 'anniversaire', color: 'pink', start_date: '2026-10-01', end_date: '2026-12-31', active: true, count: 0, total: 0 },
];

/** A tags module bound to a fake component */
function tagsContext(api = {}) {
    const module = createTagsModule(() => api);
    const context = {
        ...module.data(),
        tags: TAGS.map(t => ({ ...t })),
        transactions: [], searchResults: [], isSearching: false, searchQuery: '',
        ruleCategories: [{ id: 2, name: 'Supermarché', color: 'teal' }],
        toasts: [], tabs: [], searches: 0,
        t: (key, params) => params ? `${key} ${JSON.stringify(params)}` : key,
        showToast(text) { this.toasts.push(text); },
        apiErrorText: error => error.message,
        setActiveTab(tab) { this.tabs.push(tab); },
        runSearch() { this.searches++; },
    };
    Object.assign(context, module.methods);
    for (const [name, compute] of Object.entries(module.computed)) {
        Object.defineProperty(context, name, { get: () => compute.call(context) });
    }
    return context;
}

test('tagName: without its #, in lower case', () => {
    assert.equal(tagName('  #Voyage-Italie '), 'voyage-italie');
    assert.equal(tagName('##Été'), 'été');
    assert.equal(tagName(null), '');
});

test('tagStatus: active until its end date', () => {
    assert.equal(tagStatus({ end_date: null }, '2026-10-27'), 'active');
    assert.equal(tagStatus({ end_date: '2026-10-27' }, '2026-10-27'), 'active');
    assert.equal(tagStatus({ end_date: '2026-10-26' }, '2026-10-27'), 'archived');
});

test('tagChanges: the tags to add and to remove', () => {
    assert.deepEqual(tagChanges([1, 2], [2, 3]), { add: [3], remove: [1] });
    assert.deepEqual(tagChanges(undefined, ['4']), { add: [4], remove: [] });
    assert.deepEqual(tagChanges([1], [1]), { add: [], remove: [] });
});

test('cumulativePath: the days from left to right, zero in the scale', () => {
    const chart = cumulativePath([
        { date: '2026-07-01', cumulative: -50 },
        { date: '2026-07-03', cumulative: -100 },
        { date: '2026-07-05', cumulative: 100 },
    ], 400, 100);
    assert.deepEqual(chart.points.map(p => [p.x, p.y]), [[0, 75], [200, 100], [400, 0]]);
    assert.equal(chart.path, 'M0,75 L200,100 L400,0');
    assert.equal(chart.zero, 50);
    // One day: in the middle; none: nothing to draw
    assert.equal(cumulativePath([{ date: '2026-07-01', cumulative: -10 }], 400, 100).points[0].x, 200);
    assert.deepEqual(cumulativePath([], 400, 100), { points: [], path: '', zero: 100 });
});

test('the active tags are offered by name, the archived ones apart', () => {
    const context = tagsContext();
    assert.deepEqual(context.activeTags.map(t => t.name), ['anniversaire', 'voyage']);
    assert.deepEqual(context.archivedTags.map(t => t.name), ['ete-2020']);

    const transaction = { id: 9, tags: [1, 2] };
    assert.deepEqual(context.transactionTags(transaction).map(t => t.name), ['voyage', 'ete-2020']);
    assert.deepEqual(context.tagsToOffer(transaction).map(t => t.name), ['anniversaire']);
    assert.deepEqual(context.categoryOf(2).name, 'Supermarché');
    assert.deepEqual(context.categoryOf(7), { id: 7, name: '', color: '' });
});

test('adds and removes the tags of a transaction from its detail', async () => {
    const sent = [];
    const context = tagsContext({ setTransactionTags: async (...args) => { sent.push(args); }, fetchTags: async () => TAGS });
    const transaction = { id: 9, tags: [1] };

    context.detailTagToAdd = 3;
    await context.addDetailTag(transaction);
    assert.deepEqual(transaction.tags, [1, 3]);
    assert.equal(context.detailTagToAdd, '');
    await context.removeDetailTag(transaction, TAGS[0]);
    assert.deepEqual(transaction.tags, [3]);
    assert.deepEqual(sent, [[[9], [3], []], [[9], [], [1]]]);
});

test('tags a selection of the list at once', async () => {
    const sent = [];
    const context = tagsContext({ setTransactionTags: async (...args) => { sent.push(args); }, fetchTags: async () => TAGS });
    context.transactions = [{ id: 4, tags: [] }, { id: 5, tags: [3] }, { id: 6, tags: [] }];

    context.toggleTransactionSelection();
    assert.equal(context.selectingTransactions, true);
    context.toggleSelectedTransaction(context.transactions[0]);
    context.toggleSelectedTransaction(context.transactions[1]);
    context.toggleSelectedTransaction(context.transactions[2]);
    context.toggleSelectedTransaction(context.transactions[2]);
    assert.deepEqual(context.selectedTransactionIds, [4, 5]);

    context.selectionTag = '3';
    await context.tagSelectedTransactions();
    assert.deepEqual(sent, [[[4, 5], [3], []]]);
    assert.deepEqual(context.transactions.map(t => t.tags), [[3], [3], []]);
    assert.equal(context.selectingTransactions, false);
    assert.equal(context.toasts.length, 1);
    assert.equal(MAX_TAG_SELECTION, 500);
});

test('saves a new tag, then reloads the tags', async () => {
    const created = [];
    const context = tagsContext({ createTag: async t => { created.push(t); return t; }, fetchTags: async () => TAGS });
    context.openTagModal();
    assert.equal(context.showTagModal, true);
    assert.match(context.tagForm.start_date, /^\d{4}-\d{2}-\d{2}$/);
    context.tagForm.name = '#Noël';
    context.tagForm.end_date = '';

    await context.saveTag();
    assert.deepEqual(created, [{ name: 'noël', color: 'blue', start_date: context.tagForm.start_date, end_date: null }]);
    assert.equal(context.showTagModal, false);
    assert.deepEqual(context.toasts, ['tagSaved']);
});

test('opens the summary of a tag and its transactions in the Transactions tab', async () => {
    const context = tagsContext({ fetchTagSummary: async id => ({ tag: TAGS[0], total: -120, count: 2, categories: [{ category: 2, total: -80, count: 1 }, { category: 3, total: -40, count: 1 }], transactions: [], days: [] }) });
    await context.openTagSummary(TAGS[0]);
    assert.equal(context.tagSummary.total, -120);
    assert.equal(context.tagSummaryMax, 80);
    context.showTagTransactions(TAGS[0]);
    assert.deepEqual(context.tabs, ['transactions']);
    assert.equal(context.searchQuery, '#voyage');
    assert.equal(context.searches, 1);
    context.closeTagSummary();
    assert.equal(context.tagSummary, null);
});

test('the API of the tags', async () => {
    const base = 'https://carbure.example.com/api';
    const calls = mockFetch(() => json({ id: 1 }));
    const api = createApiService(base, 't');

    await api.createTag({ name: 'voyage', color: 'green', start_date: '2026-07-01', end_date: null });
    await api.updateTag({ id: 1, name: 'voyage', color: 'green', start_date: '2026-07-01', end_date: null });
    await api.deleteTag(1);
    await api.fetchTagSummary(1);
    await api.setTransactionTags([4, 5], [1], [2]);
    assert.deepEqual(calls.map(c => [c.url, c.options.method || 'GET']), [
        [`${base}/tag`, 'POST'], [`${base}/tag`, 'PUT'], [`${base}/tag?id=1`, 'DELETE'], [`${base}/tag/summary?id=1`, 'GET'],
        [`${base}/transaction/tags`, 'PUT']]);
    assert.deepEqual(JSON.parse(calls[4].options.body), { ids: [4, 5], add: [1], remove: [2] });

    mockFetch(() => json([{ id: 1 }]));
    assert.deepEqual(await api.fetchTags(), [{ id: 1 }]);
});
