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

test('the transactions on a phone: figures in one strip, icon buttons, full labels (#384, #389)', async ({ adminPage: page, api }) => {
    // Every transaction tagged: the chips of the recurring ones under their badge
    const tag = await (await api.post('/api/tag', { data: { name: 'voyage', color: 'green', start_date: '2020-01-01' } })).json();
    const ids = (await (await api.get('/api/transaction')).json()).map(t => t.id);
    await api.put('/api/transaction/tags', { data: { ids, add: [tag.id] } });
    await page.reload();

    // The three figures of the month side by side, compact
    const cards = page.locator('.stat-card');
    await expect(cards).toHaveCount(3);
    const tops = await cards.evaluateAll(list => list.map(card => Math.round(card.getBoundingClientRect().top)));
    expect(new Set(tops).size).toBe(1);
    expect((await cards.first().boundingBox()).height).toBeLessThan(56);

    // One scroll, the page's: the list does not scroll inside it
    const list = page.locator('.transactions-list');
    expect(await list.evaluate(element => element.scrollHeight <= element.clientHeight)).toBe(true);

    // The recurring badge and the tag chips of a row do not overlap
    const recurring = page.locator('.transaction-item').filter({ has: page.locator('.recurring-badge') }).first();
    const badge = await recurring.locator('.recurring-badge').boundingBox();
    const chip = await recurring.locator('.tag-chip').boundingBox();
    expect(chip.y >= badge.y + badge.height || chip.x >= badge.x + badge.width).toBe(true);

    // The buttons of the toolbar as icons, still named
    const toolbar = page.locator('.transactions-toolbar');
    for (const name of [tr('manualTransactionButton'), tr('importButton'), tr('recurringCalendarButton')]) {
        const button = toolbar.getByRole('button', { name, exact: true });
        await expect(button).toBeVisible();
        expect((await button.boundingBox()).width).toBeLessThan(60);
    }

    // A long label is shown whole (two lines at most), the amount next to it
    const row = page.locator('.transaction-item').filter({ hasText: 'CB RESTAURANT LE PETIT ZINC' });
    const label = row.locator('.transaction-label');
    expect(await label.evaluate(element => element.scrollWidth <= element.clientWidth)).toBe(true);
    const amount = await row.locator('.transaction-amount').boundingBox();
    const labelBox = await label.boundingBox();
    expect(amount.y).toBeLessThan(labelBox.y + labelBox.height);

    // A message does not block the buttons it covers
    expect(await page.evaluate(() => {
        const toast = document.createElement('div');
        toast.className = 'toast';
        document.body.appendChild(toast);
        const value = getComputedStyle(toast).pointerEvents;
        toast.remove();
        return value;
    })).toBe('none');

    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});
