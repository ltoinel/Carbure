// Profile page: personal information, language, devices
const { test, expect, tr } = require('../fixtures');

/**
 * Opens the profile page from the user menu
 * @param {import('@playwright/test').Page} page
 */
async function openProfile(page) {
    await page.locator('.user-menu-toggle').click();
    await page.getByRole('menuitem', { name: tr('myProfile') }).click();
    await expect(page.getByRole('heading', { name: tr('myProfile') })).toBeVisible();
}

test('modifies the personal information', async ({ userPage: page }) => {
    await openProfile(page);

    await expect(page.getByLabel(tr('username'))).toHaveValue('marie');
    await page.getByLabel(tr('firstname')).fill('Marion');
    await page.getByLabel(tr('alertThreshold')).fill('200');
    await page.getByRole('button', { name: tr('save') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('profileUpdated'));

    // Saved on the server
    await page.reload();
    await openProfile(page);
    await expect(page.getByLabel(tr('firstname'))).toHaveValue('Marion');
    await expect(page.getByLabel(tr('alertThreshold'))).toHaveValue('200');
});

test('changes the password', async ({ userPage: page, request }) => {
    await openProfile(page);

    await page.getByLabel(tr('password')).fill('new-marie-password');
    await page.getByRole('button', { name: tr('save') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('profileUpdated'));

    const login = await request.post('/api/user/login', { data: { username: 'marie', password: 'new-marie-password' } });
    expect(login.ok()).toBeTruthy();
});

test('switches the portal to English', async ({ userPage: page }) => {
    await openProfile(page);

    await page.getByLabel(tr('language')).selectOption('en');
    await page.getByRole('button', { name: tr('save') }).click();

    await expect(page.locator('.tabs').getByRole('button', { name: tr('tabTrends', {}, 'en'), exact: true })).toBeVisible();
    await expect(page.locator('html')).toHaveAttribute('lang', 'en');
});

test('shows, reveals then deletes a device', async ({ adminPage: page }) => {
    await openProfile(page);

    const device = page.locator('.device-item').filter({ hasText: 'iPhone de Ada' });
    const token = 'ab12'.repeat(16);
    await expect(device.locator('.device-token')).toHaveText(`${token.slice(0, 6)}…${token.slice(-6)}`);
    await device.getByRole('button', { name: tr('showToken') }).click();
    await expect(device.locator('.device-token')).toHaveText(token);

    await device.getByRole('button', { name: tr('deleteDevice') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('deviceDeleted'));
    await expect(page.locator('.transactions-hint')).toHaveText(tr('noDevices'));
});
