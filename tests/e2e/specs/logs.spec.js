// Logs tab (administrators): files, level, search, entries of a request
const { test, expect, tr, openTab } = require('../fixtures');

test('shows the entries of the log of the day, filtered', async ({ adminPage: page }) => {
    await openTab(page, tr('tabLogs'));

    // Requests of the tests are logged in carbure_e2e_YYYYMMDD.log
    await expect(page.getByLabel(tr('logsFile'))).toHaveValue(/carbure_e2e_\d{8}\.log/);
    await expect(page.locator('.log-entry').first()).toBeVisible();

    await page.getByLabel(tr('logsLevel')).selectOption('ERROR');
    await expect(page.locator('.log-entry:not(.log-error)')).toHaveCount(0);

    await page.getByLabel(tr('logsLevel')).selectOption('DEBUG');
    await page.getByLabel(tr('logsSearch')).fill('/api/budget');
    await page.getByRole('button', { name: tr('refreshButton') }).last().click();
    await expect(page.locator('.log-entry').first()).toContainText('/api/budget');
});

test('shows all the entries of a request', async ({ adminPage: page }) => {
    await openTab(page, tr('tabLogs'));

    const uid = page.locator('.log-uid').first();
    const value = (await uid.textContent()).trim();
    await uid.click();
    // A text search: the entries of the request, and the ones mentioning it (the search itself)
    await expect(async () => {
        const entries = await page.locator('.log-entry').allTextContents();
        expect(entries.length).toBeGreaterThan(0);
        expect(entries.filter(entry => !entry.includes(value))).toEqual([]);
    }).toPass();
    await expect(page.locator('.log-uid', { hasText: value }).first()).toBeVisible();
});
