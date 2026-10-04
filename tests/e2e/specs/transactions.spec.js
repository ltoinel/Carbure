// Transactions tab: month view, totals, search, check, categorization, detail modal
const { test, expect, tr, openTab, pickCategory, euros, currentMonth } = require('../fixtures');

/** Row of a transaction, by its label */
const row = (page, label) => page.locator('.transaction-item').filter({ hasText: label });

test('lists the transactions of the month with their totals', async ({ adminPage: page }) => {
    await expect(page.locator('.transaction-item')).toHaveCount(6);
    await expect(page.locator('.transaction-count')).toHaveText(tr('transactionCount', { count: 6 }));

    const stats = page.locator('.stat-card');
    await expect(stats.filter({ hasText: tr('incomeLabel') }).locator('.stat-value')).toHaveText(euros(3200));
    await expect(stats.filter({ hasText: tr('expenseLabel') }).locator('.stat-value')).toHaveText(euros(1584.2));
    await expect(stats.filter({ hasText: tr('balanceLabel') }).locator('.stat-value')).toHaveText(euros(1615.8));

    await expect(row(page, 'CB SUPERMARCHE CASINO').locator('.transaction-amount')).toHaveText(euros(-82.4));
    await expect(row(page, 'CB SUPERMARCHE CASINO').locator('.category-chip')).toHaveText('Supermarché');
});

test('shows the icon and the color of the category of each transaction', async ({ adminPage: page }) => {
    // Icon of the category itself (not of its parent), as on the categories page
    await expect(row(page, 'CB SUPERMARCHE CASINO').locator('.transaction-icon .material-icons')).toHaveText('shopping_cart');
    await expect(row(page, 'CB RESTAURANT LE PETIT ZINC').locator('.transaction-icon .material-icons')).toHaveText('local_dining');
    await expect(row(page, 'PRLV LOYER AGENCE').locator('.transaction-icon .material-icons')).toHaveText('home');
    // Uncategorized: the icon of the type of operation (card)
    await expect(row(page, 'CB BOULANGERIE PAUL').locator('.transaction-icon')).toHaveClass(/type-7/);

    await openTab(page, tr('tabCategories'));
    const category = page.locator('.category-row').filter({ has: page.locator('.category-row-name', { hasText: /^Restaurant$/ }) });
    await expect(category.locator('.material-icons').first()).toHaveText('local_dining');
});

test('shows another month', async ({ adminPage: page }) => {
    const previous = new Date();
    previous.setDate(1);
    previous.setMonth(previous.getMonth() - 1);
    await page.locator('#year').selectOption(String(previous.getFullYear()));
    await page.locator('#month').selectOption(String(previous.getMonth() + 1));

    await expect(page.locator('.transaction-item')).toHaveCount(3);
    await expect(row(page, 'CB SUPERMARCHE CARREFOUR')).toBeVisible();
});

test('searches the transactions of every month by label', async ({ adminPage: page }) => {
    await page.getByRole('searchbox', { name: tr('searchPlaceholder') }).fill('LOYER');

    await expect(page.locator('.transaction-item')).toHaveCount(3);
    await expect(page.locator('.search-total')).toContainText(tr('searchTotal', { count: 3 }));
    await expect(page.locator('.search-total strong')).toHaveText(euros(-2850));

    await page.getByRole('button', { name: tr('clearSearch') }).click();
    await expect(page.locator('.transaction-item')).toHaveCount(6);

    await page.getByRole('searchbox', { name: tr('searchPlaceholder') }).fill('INTROUVABLE');
    await expect(page.locator('.transactions-hint')).toHaveText(tr('noSearchResults'));
});

test('filters the transactions not checked yet', async ({ adminPage: page }) => {
    const toggle = page.locator('label.toggle-switch');
    await expect(page.locator('.toggle-label')).toHaveText(tr('uncheckedCount', { count: 3 }));

    await toggle.click();
    await expect(page.getByRole('switch')).toBeChecked();
    await expect(page.locator('.transaction-item')).toHaveCount(3);

    await toggle.click();
    await expect(page.locator('.transaction-item')).toHaveCount(6);
});

test('checks and unchecks a transaction', async ({ adminPage: page, api }) => {
    const transaction = row(page, 'CB SUPERMARCHE CASINO');
    await transaction.getByRole('button', { name: tr('checkTransaction') }).click();
    await expect(transaction.getByRole('button', { name: tr('uncheckTransaction') })).toHaveAttribute('aria-pressed', 'true');
    await expect(page.locator('.toggle-label')).toHaveText(tr('uncheckedCount', { count: 2 }));

    const { month, year } = currentMonth();
    const saved = await (await api.get(`/api/transaction?month=${month}&year=${year}`)).json();
    expect(Number(saved.find(t => t.label === 'CB SUPERMARCHE CASINO').pointed)).toBe(1);

    await page.reload();
    await expect(row(page, 'CB SUPERMARCHE CASINO').getByRole('button', { name: tr('uncheckTransaction') })).toBeVisible();
});

test('categorizes a transaction and proposes a rule', async ({ adminPage: page }) => {
    const transaction = row(page, 'CB BOULANGERIE PAUL');
    await pickCategory(transaction, 'Alimentation');

    await expect(transaction.locator('.category-chip')).toHaveText('Alimentation');
    const suggestion = page.locator('.rule-suggestion');
    await expect(suggestion).toContainText(tr('ruleSuggestion', { label: 'CB BOULANGERIE PAUL', category: 'Alimentation' }));

    // The rule modal opens on the rules tab, filled in
    await suggestion.getByRole('button', { name: tr('createRule') }).click();
    await expect(page.locator('.tabs .tab-button.active')).toHaveAccessibleName(tr('tabRules'));
    await expect(page.locator('#rule-keyword')).toHaveValue('CB BOULANGERIE PAUL');
    await page.getByRole('button', { name: tr('save') }).click();
    await expect(page.getByRole('button', { name: `${tr('editRule')} CB BOULANGERIE PAUL` })).toBeVisible();
});

test('a user categorizes without being offered a rule', async ({ userPage: page }) => {
    const transaction = row(page, 'CB BOULANGERIE PAUL');
    await pickCategory(transaction, 'Alimentation');

    await expect(transaction.locator('.category-chip')).toHaveText('Alimentation');
    await expect(page.locator('.rule-suggestion')).toHaveCount(0);
});

test('changes the category and the checked state in the detail modal', async ({ adminPage: page, api }) => {
    await row(page, 'CB RESTAURANT LE PETIT ZINC').getByRole('button', { name: new RegExp(tr('transactionDetail')) }).click();

    const modal = page.getByRole('dialog', { name: tr('transactionDetail') });
    await expect(modal.locator('.transaction-detail-amount')).toHaveText(euros(-45));
    await pickCategory(modal, 'Supermarché');
    await modal.getByLabel(tr('transactionChecked')).check();
    await modal.getByRole('button', { name: tr('save') }).click();

    await expect(modal).toHaveCount(0);
    await expect(page.locator('.toast')).toHaveText(tr('transactionSaved'));
    await expect(row(page, 'CB RESTAURANT LE PETIT ZINC').locator('.category-chip')).toHaveText('Supermarché');

    const { month, year } = currentMonth();
    const saved = (await (await api.get(`/api/transaction?month=${month}&year=${year}`)).json())
        .find(t => t.label === 'CB RESTAURANT LE PETIT ZINC');
    expect(Number(saved.category)).toBe(2);
    expect(Number(saved.pointed)).toBe(1);
});

test('closes the detail modal with Escape', async ({ adminPage: page }) => {
    await row(page, 'PRLV LOYER AGENCE').getByRole('button', { name: new RegExp(tr('transactionDetail')) }).click();
    await expect(page.getByRole('dialog')).toBeVisible();

    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).toHaveCount(0);
});
