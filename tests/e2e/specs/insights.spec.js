// Insights tab: amounts of the month, insights hidden and shown again (+ button),
// management of the insights by an administrator
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
    // Only the administrators manage the insights (the user hides one and sets his alert); nothing hidden to add again
    await expect(page.getByRole('button', { name: tr('addInsight') })).toHaveCount(0);
    await expect(expenses.getByRole('button')).toHaveCount(2);
});

test('hides an insight, then shows it again with the + button', async ({ userPage: page }) => {
    await openTab(page, tr('tabInsights'));
    await card(page, 'Dépenses').getByRole('button', { name: tr('hideInsightOf', { name: 'Dépenses' }) }).click();
    await expect(page.locator('.toast')).toHaveText(tr('insightHidden', { name: 'Dépenses' }));
    await expect(page.locator('.insight-card')).toHaveCount(0);

    await page.getByRole('button', { name: tr('addInsight') }).click();
    const catalog = page.getByRole('dialog', { name: tr('addInsight') });
    await expect(catalog.locator('.insight-catalog-name')).toHaveText(['Dépenses']);
    // Only the administrators create an insight
    await expect(catalog.getByRole('button', { name: tr('createInsight') })).toHaveCount(0);
    await catalog.getByRole('button', { name: tr('showInsightOf', { name: 'Dépenses' }) }).click();

    // The last hidden one shown: the list closes
    await expect(catalog).toHaveCount(0);
    await expect(card(page, 'Dépenses').locator('.insight-value')).toHaveText(euros(1584.2));
    await expect(page.getByRole('button', { name: tr('addInsight') })).toHaveCount(0);
});

test('adds an insight whose query is checked while typing', async ({ adminPage: page }) => {
    await openTab(page, tr('tabInsights'));
    await page.getByRole('button', { name: tr('addInsight') }).click();
    await page.getByRole('button', { name: tr('createInsight') }).click();

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
    await page.getByRole('button', { name: tr('createInsight') }).click();

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

test('sets an alert on an insight, changes it, then removes it', async ({ userPage: page }) => {
    await openTab(page, tr('tabInsights'));
    const expenses = card(page, 'Dépenses');
    const bell = expenses.getByRole('button', { name: tr('insightAlertOf', { name: 'Dépenses' }) });
    await bell.click();

    const modal = page.getByRole('dialog', { name: tr('insightAlertOf', { name: 'Dépenses' }) });
    const amount = modal.getByLabel(tr('insightAlertThreshold'));
    await expect(amount).toBeFocused();
    await expect(modal.getByRole('radio', { name: tr('insightAlertDirection_above') })).toBeChecked();
    // Nothing to remove yet
    await expect(modal.getByRole('button', { name: tr('insightAlertRemove') })).toHaveCount(0);

    await amount.fill('2000');
    await modal.getByRole('button', { name: tr('save') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('insightAlertSaved', { name: 'Dépenses' }));
    await expect(modal).toHaveCount(0);
    await expect(bell).toBeFocused();
    await expect(expenses.locator('.insight-alert-text')).toHaveText(tr('insightAlert_above', { amount: euros(2000) }));

    // Aligned (#415): the amount and the alert on one line each, the actions inside the card,
    // at the bottom of the cards of the same row
    for (const selector of ['.insight-value', '.insight-alert-text']) {
        const box = await expenses.locator(selector).boundingBox();
        expect(box.height).toBeLessThan(32);
    }
    const cardBox = await expenses.boundingBox();
    const actionsBox = await expenses.locator('.insight-actions').boundingBox();
    expect(actionsBox.x + actionsBox.width).toBeLessThanOrEqual(cardBox.x + cardBox.width);
    const footers = await page.locator('.insight-footer').evaluateAll(list => list.map(footer => {
        const card = footer.closest('.insight-card').getBoundingClientRect();
        return { row: Math.round(card.top), bottom: Math.round(footer.getBoundingClientRect().bottom) };
    }));
    const row = footers.filter(footer => footer.row === footers[0].row);
    expect(new Set(row.map(footer => footer.bottom)).size).toBe(1);

    // Kept after a reload, then changed to below
    await page.reload();
    await openTab(page, tr('tabInsights'));
    await expect(expenses.locator('.insight-alert-text')).toHaveText(tr('insightAlert_above', { amount: euros(2000) }));
    await bell.click();
    await expect(amount).toHaveValue('2000');
    await modal.getByRole('radio', { name: tr('insightAlertDirection_below') }).check();
    await amount.fill('500,5');
    await modal.getByRole('button', { name: tr('save') }).click();
    await expect(expenses.locator('.insight-alert-text')).toHaveText(tr('insightAlert_below', { amount: euros(500.5) }));

    await bell.click();
    // The small red trash button at the bottom left of the footer, as in a transaction detail
    const remove = modal.getByRole('button', { name: tr('insightAlertRemove') });
    await expect(remove).toHaveClass(/btn-danger-icon/);
    await expect(remove).toHaveClass(/modal-footer-start/);
    await expect(remove.locator('.material-icons')).toHaveText('delete');
    await remove.click();
    await expect(page.locator('.toast')).toHaveText(tr('insightAlertRemoved', { name: 'Dépenses' }));
    await expect(expenses.locator('.insight-alert-state')).toHaveCount(0);
});
