// Insights tab: amounts of the month, management of the insights by an administrator
const { test, expect, tr, openTab, euros } = require('../fixtures');

/** Card of an insight */
const card = (page, name) => page.locator('.insight-card').filter({ has: page.locator('.insight-label', { hasText: new RegExp(`^${name}$`) }) });

/**
 * Types a query in the SQL editor (CodeMirror) of the insight modal
 * @param {import('@playwright/test').Page} page
 * @param {string} sql
 */
async function setSql(page, sql) {
    await page.locator('.modal-insight .CodeMirror').evaluate((element, value) => element.CodeMirror.setValue(value), sql);
}

test('shows the amount of each insight for the month', async ({ userPage: page }) => {
    await openTab(page, tr('tabInsights'));

    const expenses = card(page, 'Dépenses');
    await expect(expenses.locator('.insight-value')).toHaveText(euros(1584.2));
    await expect(expenses.locator('.insight-value')).toHaveClass(/negative/);
    // Only the administrators manage the insights
    await expect(page.getByRole('button', { name: tr('addInsight') })).toHaveCount(0);
    await expect(expenses.getByRole('button')).toHaveCount(0);
});

test('adds an insight whose query is checked while typing', async ({ adminPage: page }) => {
    await openTab(page, tr('tabInsights'));
    await page.getByRole('button', { name: tr('addInsight') }).click();

    const modal = page.getByRole('dialog', { name: tr('addInsight') });
    await modal.getByLabel(new RegExp(tr('insightName'))).fill('Loyer');
    await modal.getByRole('radio', { name: tr('insightColor_blue') }).click();
    await modal.getByRole('radio', { name: 'home', exact: true }).click();

    await setSql(page, 'SELECT SUM(amount) AS amount FROM bank_transaction WHERE category = 4 AND MONTH(date) = {month} AND YEAR(date) = {year}');
    await expect(modal.locator('.sql-check')).toContainText(tr('insightValid', { amount: euros(-950) }));

    await modal.getByRole('button', { name: tr('save') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('insightAdded'));
    await expect(card(page, 'Loyer').locator('.insight-value')).toHaveText(euros(950));
    await expect(card(page, 'Loyer').locator('.insight-icon')).toHaveText('home');
});

test('refuses a query that writes', async ({ adminPage: page }) => {
    await openTab(page, tr('tabInsights'));
    await page.getByRole('button', { name: tr('addInsight') }).click();

    const modal = page.getByRole('dialog', { name: tr('addInsight') });
    await modal.getByLabel(new RegExp(tr('insightName'))).fill('Piège');
    await setSql(page, 'DELETE FROM bank_transaction');
    await expect(modal.locator('.sql-check')).toHaveClass(/sql-check-invalid/);

    await modal.getByRole('button', { name: tr('save') }).click();
    await expect(modal.getByRole('alert')).toBeVisible();
    await expect(modal).toBeVisible();
});

test('modifies then deletes an insight', async ({ adminPage: page }) => {
    await openTab(page, tr('tabInsights'));
    await card(page, 'Dépenses').getByRole('button', { name: `${tr('editInsight')} Dépenses` }).click();

    let modal = page.getByRole('dialog', { name: tr('editInsight') });
    await expect(modal.locator('.CodeMirror')).toContainText('SELECT SUM(amount)');
    await modal.getByLabel(new RegExp(tr('insightName'))).fill('Sorties');
    await modal.getByRole('button', { name: tr('save') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('insightUpdated'));
    await expect(card(page, 'Sorties')).toBeVisible();

    await card(page, 'Sorties').getByRole('button', { name: `${tr('editInsight')} Sorties` }).click();
    modal = page.getByRole('dialog', { name: tr('editInsight') });
    await modal.getByRole('button', { name: tr('deleteInsight') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('insightDeleted'));
    await expect(page.locator('.insight-card')).toHaveCount(0);
});
