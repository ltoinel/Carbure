// Insights shown or hidden (portal/modules/insightsModule.js)
import { mockFetch, json } from './setup.mjs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createInsightsModule } from '../../portal/modules/insightsModule.js';
import { createApiService } from '../../portal/services/apiService.js';

/** An insights module bound to a fake component */
function insightsContext(api) {
    const module = createInsightsModule(() => api);
    const context = {
        ...module.data(),
        selectedMonth: 10, selectedYear: 2026, isDebugMode: false,
        toasts: [],
        t: (key, params) => params ? `${key} ${JSON.stringify(params)}` : key,
        showToast(text) { this.toasts.push(text); },
        apiErrorText: error => error.message,
        getInsightName: name => name,
        $nextTick() {},
    };
    Object.assign(context, module.methods);
    return context;
}

/** A fake API: the shown insights and the hidden ones, from a list with enabled */
function fakeApi(all) {
    return {
        fetchInsights: async () => all.filter(i => i.enabled).map(({ enabled, ...i }) => ({ ...i, amount: 10 })),
        fetchDisabledInsights: async () => all.filter(i => !i.enabled).map(({ enabled, ...i }) => i),
        setInsightEnabled: async (id, enabled) => { all.find(i => i.id === id).enabled = enabled; return { id, enabled }; },
    };
}

test('loads the shown insights and the hidden ones', async () => {
    const context = insightsContext(fakeApi([{ id: 1, name: 'Dépenses', enabled: true }, { id: 2, name: 'Retraits', enabled: false }]));
    await context.loadInsights(10, 2026);
    assert.deepEqual(context.insights.map(i => i.id), [1]);
    assert.deepEqual(context.disabledInsights.map(i => i.id), [2]);
});

test('hides an insight, then shows it again from the + list', async () => {
    const context = insightsContext(fakeApi([{ id: 1, name: 'Dépenses', enabled: true }, { id: 2, name: 'Retraits', enabled: false }]));
    await context.loadInsights(10, 2026);

    await context.setInsightEnabled(context.insights[0], false);
    assert.deepEqual(context.insights, []);
    assert.deepEqual(context.disabledInsights.map(i => i.id), [1, 2]);
    assert.deepEqual(context.toasts, ['insightHidden {"name":"Dépenses"}']);

    context.openInsightCatalog();
    await context.setInsightEnabled(context.disabledInsights[1], true);
    // Another hidden one: the list stays open
    assert.equal(context.showInsightCatalog, true);
    await context.setInsightEnabled(context.disabledInsights[0], true);
    // The last one shown: the list closes
    assert.equal(context.showInsightCatalog, false);
    assert.deepEqual(context.insights.map(i => i.id), [1, 2]);
    assert.equal(context.togglingInsight, null);
});

test('a refused change is shown, a failed list of the hidden ones gives none', async () => {
    const context = insightsContext({
        setInsightEnabled: async () => { throw new Error('Insight not found'); },
        fetchDisabledInsights: async () => { throw new Error('offline'); },
    });
    await context.setInsightEnabled({ id: 9, name: 'X' }, true);
    assert.deepEqual(context.toasts, ['Insight not found']);
    context.disabledInsights = [{ id: 3 }];
    await context.loadDisabledInsights();
    assert.deepEqual(context.disabledInsights, []);
});

test('the administrators create an insight from the + list', async () => {
    const context = insightsContext({});
    context.showInsightCatalog = true;
    await context.createInsightFromCatalog();
    assert.equal(context.showInsightCatalog, false);
    assert.equal(context.insightForm.id, null);
    context.closeInsightCatalog();
});

test('the API of the hidden insights', async () => {
    const base = 'https://carbure.example.com/api';
    const calls = mockFetch(() => json([{ id: 2, name: 'Retraits', color: 'gray', icon: null }]));
    const api = createApiService(base, 't');

    assert.deepEqual(await api.fetchDisabledInsights(), [{ id: 2, name: 'Retraits', color: 'gray', icon: null }]);
    await api.setInsightEnabled(2, true);
    assert.deepEqual(calls.map(c => [c.url, c.options.method || 'GET']), [[`${base}/insight/disabled`, 'GET'], [`${base}/insight/enabled`, 'PUT']]);
    assert.deepEqual(JSON.parse(calls[1].options.body), { id: 2, enabled: true });
});
