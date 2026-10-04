// Trends tab: key figures, period, comparison, charts and table
const { test, expect, tr, openTab, euros } = require('../fixtures');

test('shows the trends of the last 12 months', async ({ userPage: page }) => {
    await openTab(page, tr('tabTrends'));

    await expect(page.getByRole('heading', { name: tr('trendsTitle', { months: 12 }) })).toBeVisible();
    // 500 put aside on the Livret A (sub-category of Épargne) this month
    const savings = page.locator('.trends-stat').filter({ hasText: tr('trendsTotalSavings') });
    await expect(savings.locator('.summary-value')).toHaveText(euros(500));
    await expect(page.getByRole('img', { name: tr('trendsFlowsTitle') })).toBeVisible();
    await expect(page.getByRole('img', { name: tr('trendsSavingsTitle') })).toBeVisible();

    await page.getByRole('button', { name: tr('trendsShowTable') }).click();
    await expect(page.locator('.trends-table tbody tr')).toHaveCount(12);
});

test('changes the period and compares it', async ({ userPage: page }) => {
    await openTab(page, tr('tabTrends'));

    await page.getByRole('button', { name: tr('monthsShort', { months: 3 }) }).click();
    await expect(page.getByRole('heading', { name: tr('trendsTitle', { months: 3 }) })).toBeVisible();
    await expect(page.getByRole('button', { name: tr('monthsShort', { months: 3 }) })).toHaveAttribute('aria-pressed', 'true');

    await page.getByRole('combobox', { name: tr('compareWith') }).selectOption('previous');
    await expect(page.getByRole('heading', { name: tr('comparisonTitle') })).toBeVisible();
    await expect(page.locator('.comparison-row').first()).toBeVisible();

    await page.getByRole('button', { name: tr('trendsShowTable') }).click();
    await expect(page.locator('.trends-table tbody tr')).toHaveCount(3);
});
