// The portal on a phone (#353): the tabs of every day in a bottom navigation, the others,
// the profile, the theme and the logout in its menu "More"; the period on one line
const { test, expect, tr } = require('../fixtures');

test.use({ viewport: { width: 390, height: 844 }, hasTouch: true });

test('the bottom navigation replaces the tabs, the other tabs in its menu', async ({ adminPage: page }) => {
    const bar = page.locator('.bottom-nav');
    await expect(page.locator('.tabs')).toBeHidden();
    await expect(bar.getByRole('button')).toHaveCount(5);
    await expect(bar.getByRole('button', { name: tr('tabTransactions') })).toHaveAttribute('aria-current', 'page');

    await bar.getByRole('button', { name: tr('tabBudget') }).click();
    await expect(bar.getByRole('button', { name: tr('tabBudget') })).toHaveAttribute('aria-current', 'page');

    // "More": the tabs of the administrators, opened from it, the menu then closed
    const more = bar.getByRole('button', { name: tr('navMore') });
    await more.click();
    const menu = page.getByRole('dialog', { name: 'admin' });
    await expect(menu.getByRole('button', { name: tr('tabRules') })).toBeFocused();
    await menu.getByRole('button', { name: tr('tabRules') }).click();
    await expect(menu).toHaveCount(0);
    await expect(page.getByRole('heading', { name: tr('rulesCount', { count: 4 }) })).toBeVisible();
    await expect(more).toHaveClass(/active/);

    // Escape closes it, the focus back on "More"
    await more.click();
    await expect(menu).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(menu).toHaveCount(0);
    await expect(more).toBeFocused();

    // Nothing wider than the screen
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});

test('the period on one line, with the previous and next months', async ({ adminPage: page }) => {
    const header = await page.locator('.header').boundingBox();
    expect(header.height).toBeLessThan(80);
    const month = page.locator('#month');
    const current = await month.inputValue();

    // The current month is the newest one
    await expect(page.getByRole('button', { name: tr('periodNext') })).toBeDisabled();
    await page.getByRole('button', { name: tr('periodPrevious') }).click();
    await expect(month).not.toHaveValue(current);
    await page.getByRole('button', { name: tr('periodNext') }).click();
    await expect(month).toHaveValue(current);
});

test('a user without administration finds the AI agents and the logout in the menu', async ({ userPage: page }) => {
    await page.locator('.bottom-nav').getByRole('button', { name: tr('navMore') }).click();
    const menu = page.getByRole('dialog', { name: 'marie' });
    await expect(menu.getByRole('button', { name: tr('tabRules') })).toHaveCount(0);
    await menu.getByRole('button', { name: tr('tabAgents') }).click();
    await expect(page.locator('.tab-panel')).toBeVisible();

    await page.locator('.bottom-nav').getByRole('button', { name: tr('navMore') }).click();
    await page.getByRole('dialog', { name: 'marie' }).getByRole('button', { name: tr('logout') }).click();
    await expect(page.getByRole('button', { name: tr('loginButton') })).toBeVisible();
});
