// stores/budgetStore.js, with a fake API
import './setup.mjs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createBudgetStore } from '../../portal/stores/budgetStore.js';

/** Fake API: budgets by parent category (0: top level) */
function fakeApi(levels) {
    const api = {
        calls: [],
        async fetchBudget(month, year, category = null) {
            api.calls.push(['fetchBudget', month, year, category]);
            return levels[category ?? 0] || [];
        },
        async updateBudget(id, month, year, amount) {
            api.calls.push(['updateBudget', id, month, year, amount]);
        },
        async copyBudgets(month, year, overwrite) {
            api.calls.push(['copyBudgets', month, year, overwrite]);
            return { month: '2026-11', source: '2026-10', copied: 2, overwrite };
        },
        abortAllExcept() {},
    };
    return api;
}

const LEVELS = {
    0: [{ id: 1, name: 'Alimentation', progress: 32 }, { id: 4, name: 'Logement', progress: 95 }, { id: 9, name: '' }],
    1: [{ id: 2, name: 'Supermarché', progress: 27 }, { id: 3, name: 'Restaurant', progress: 45 }],
};

test('loads the top level, most consumed first, without the nameless items', async () => {
    const store = createBudgetStore(fakeApi(LEVELS));
    await store.loadRootBudgets(10, 2026);

    assert.deepEqual(store.state.currentList.map(i => i.name), ['Logement', 'Alimentation']);
    assert.deepEqual(store.state.breadcrumbStack, []);
    assert.equal(store.state.isLoading, false);
});

test('goes into the sub-categories, once fetched they are cached', async () => {
    const api = fakeApi(LEVELS);
    const store = createBudgetStore(api);
    await store.loadRootBudgets(10, 2026);

    assert.equal(await store.navigateToChildren(LEVELS[0][0], 10, 2026), true);
    assert.deepEqual(store.state.breadcrumbStack, [{ id: 1, name: 'Alimentation' }]);
    assert.deepEqual(store.state.currentList.map(i => i.name), ['Supermarché', 'Restaurant']);

    await store.navigateToBreadcrumb(-1, 10, 2026);
    assert.deepEqual(store.state.breadcrumbStack, []);
    await store.navigateToChildren(LEVELS[0][0], 10, 2026);
    assert.equal(api.calls.filter(c => c[3] === 1).length, 1);
});

test('stays on the level of a category without sub-categories', async () => {
    const store = createBudgetStore(fakeApi(LEVELS));
    await store.loadRootBudgets(10, 2026);

    assert.equal(await store.navigateToChildren(LEVELS[0][1], 10, 2026), false);
    assert.deepEqual(store.state.breadcrumbStack, []);
    assert.equal(store.state.currentList.length, 2);
});

test('after a change of budget, reloads and stays on the displayed level', async () => {
    const api = fakeApi(LEVELS);
    const store = createBudgetStore(api);
    await store.loadRootBudgets(10, 2026);
    await store.navigateToChildren(LEVELS[0][0], 10, 2026);

    await store.updateBudget(2, 10, 2026, 350);
    assert.deepEqual(api.calls.find(c => c[0] === 'updateBudget'), ['updateBudget', 2, 10, 2026, 350]);
    assert.deepEqual(store.state.breadcrumbStack, [{ id: 1, name: 'Alimentation' }]);
    // The parent depends on its sub-categories: fetched again, not taken from the cache
    assert.equal(api.calls.filter(c => c[0] === 'fetchBudget' && c[3] === 1).length, 2);
});

test('after a copy of the previous month, reloads and stays on the displayed level', async () => {
    const api = fakeApi(LEVELS);
    const store = createBudgetStore(api);
    await store.loadRootBudgets(11, 2026);
    await store.navigateToChildren(LEVELS[0][0], 11, 2026);

    const result = await store.copyBudgets(11, 2026, true);
    assert.deepEqual(api.calls.find(c => c[0] === 'copyBudgets'), ['copyBudgets', 11, 2026, true]);
    assert.deepEqual(result, { month: '2026-11', source: '2026-10', copied: 2, overwrite: true });
    assert.deepEqual(store.state.breadcrumbStack, [{ id: 1, name: 'Alimentation' }]);
    // The parent depends on its sub-categories: fetched again, not taken from the cache
    assert.equal(api.calls.filter(c => c[0] === 'fetchBudget' && c[3] === 1).length, 2);
});

test('fetches the sub-categories again at each load of the budgets (#402)', async () => {
    const api = fakeApi(LEVELS);
    const store = createBudgetStore(api);
    await store.loadRootBudgets(9, 2026);
    await store.navigateToChildren(LEVELS[0][0], 9, 2026);

    // Change of month: the sub-categories of September are not shown in October
    await store.loadRootBudgets(10, 2026);
    await store.navigateToChildren(LEVELS[0][0], 10, 2026);
    // Reload of the same month (data changed elsewhere): fetched again too
    await store.loadRootBudgets(10, 2026);
    await store.navigateToChildren(LEVELS[0][0], 10, 2026);

    assert.deepEqual(api.calls.filter(c => c[3] === 1).map(c => `${c[1]}/${c[2]}`), ['9/2026', '10/2026', '10/2026']);
});

test('a sub-category answer of another month never feeds the displayed month', async () => {
    const api = fakeApi(LEVELS);
    const store = createBudgetStore(api);
    await store.loadRootBudgets(10, 2026);
    // Children of September fetched while October is displayed (slow answer after a change of month)
    await store.navigateToChildren(LEVELS[0][0], 9, 2026);
    await store.navigateToBreadcrumb(-1, 10, 2026);

    await store.navigateToChildren(LEVELS[0][0], 10, 2026);
    assert.deepEqual(api.calls.filter(c => c[3] === 1).map(c => c[1]), [9, 10]);
});

test('goes back to a level no longer cached without repeating it in the breadcrumb', async () => {
    const levels = { ...LEVELS, 2: [{ id: 6, name: 'Bio', progress: 10 }] };
    const store = createBudgetStore(fakeApi(levels));
    await store.loadRootBudgets(10, 2026);
    await store.navigateToChildren(levels[0][0], 10, 2026);
    await store.navigateToChildren(levels[1][0], 10, 2026);
    store.state.childrenCache = {};

    await store.navigateToBreadcrumb(0, 10, 2026);
    assert.deepEqual(store.state.breadcrumbStack, [{ id: 1, name: 'Alimentation' }]);
    assert.deepEqual(store.state.currentList.map(i => i.name), ['Supermarché', 'Restaurant']);
});

test('keeps the error of a failed load', async t => {
    t.mock.method(console, 'error', () => {});
    const store = createBudgetStore({ async fetchBudget() { throw new Error('Failed to fetch budget (500)'); } });
    await store.loadRootBudgets(10, 2026);

    assert.equal(store.state.error, 'Failed to fetch budget (500)');
    assert.deepEqual(store.state.currentList, []);
});
