// Installation wizard. The e2e server is already installed: the answers of
// /api/setup (src/lib/Setup.php) are simulated, the screens and their checks are tested.
const { test, expect, tr } = require('../fixtures');

/**
 * Simulates a server that is not installed yet
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<Array<Object>>} Bodies of the requests sent to the wizard
 */
async function notInstalled(page) {
    const sent = [];
    await page.route('**/api/setup', route => route.fulfill({
        json: { setup: true, defaults: { db_host: 'db', db_port: 3306, db_name: 'carbure', db_user: 'carbure', admin_user: 'admin', language: 'fr' } },
    }));
    await page.route('**/api/setup/database', route => {
        sent.push({ step: 'database', ...route.request().postDataJSON() });
        return route.fulfill({ json: { state: 'none', version: null, pending: [], hasAdmin: false } });
    });
    await page.route('**/api/setup/install', route => {
        sent.push({ step: 'install', ...route.request().postDataJSON() });
        return route.fulfill({ json: { version: '2026-10-13_base', username: route.request().postDataJSON().admin_user } });
    });
    return sent;
}

test('installs Carbure in three steps', async ({ page }) => {
    const sent = await notInstalled(page);
    await page.goto('/portal/');

    await expect(page.getByRole('heading', { name: tr('setupTitle') })).toBeVisible();
    await expect(page.getByLabel(tr('setupDbHost'))).toHaveValue('db');
    await page.getByLabel(tr('setupDbPassword')).fill('secret-db');
    await page.getByRole('button', { name: tr('setupCheckDatabase') }).click();

    await expect(page.getByText(tr('setupState_none'))).toBeVisible();
    await expect(page.getByRole('heading', { name: tr('setupAdminTitle') })).toBeVisible();
    await page.getByLabel(tr('password'), { exact: true }).fill('admin-password');
    await page.getByLabel(tr('setupPasswordConfirm')).fill('admin-password');
    await page.getByRole('button', { name: tr('setupInstall') }).click();

    await expect(page.getByRole('heading', { name: tr('setupDoneTitle') })).toBeVisible();
    expect(sent.map(s => s.step)).toEqual(['database', 'install']);
    expect(sent[1]).toMatchObject({ db_host: 'db', db_password: 'secret-db', admin_user: 'admin', admin_password: 'admin-password',
        starter_categories: true, starter_insights: true });

    // Then the login screen, with the administrator filled in
    await page.getByRole('button', { name: tr('loginButton') }).click();
    await expect(page.locator('#loginUsername')).toHaveValue('admin');
    await expect(page.locator('#loginPassword')).toBeFocused();
});

test('checks the password of the administrator before installing', async ({ page }) => {
    const sent = await notInstalled(page);
    await page.goto('/portal/');
    await page.getByRole('button', { name: tr('setupCheckDatabase') }).click();

    // The password of the database has the same label: wait for the second step
    await expect(page.getByRole('heading', { name: tr('setupAdminTitle') })).toBeVisible();
    await page.getByLabel(tr('password'), { exact: true }).fill('admin-password');
    await page.getByLabel(tr('setupPasswordConfirm')).fill('other-password');
    await page.getByRole('button', { name: tr('setupInstall') }).click();

    await expect(page.getByRole('alert')).toContainText(tr('setupPasswordMismatch'));
    expect(sent.map(s => s.step)).toEqual(['database']);
});

test('offers the starter data on a new database', async ({ page }) => {
    const sent = await notInstalled(page);
    await page.goto('/portal/');
    await page.getByRole('button', { name: tr('setupCheckDatabase') }).click();

    const group = page.getByRole('group', { name: tr('setupStarterTitle') });
    const categories = group.getByLabel(tr('setupStarterCategories'));
    const insights = group.getByLabel(tr('setupStarterInsights'));
    await expect(categories).toBeChecked();
    await expect(insights).toBeChecked();

    // The insights use the starter categories
    await categories.uncheck();
    await expect(insights).toBeDisabled();

    await page.getByLabel(tr('password'), { exact: true }).fill('admin-password');
    await page.getByLabel(tr('setupPasswordConfirm')).fill('admin-password');
    await page.getByRole('button', { name: tr('setupInstall') }).click();
    await expect(page.getByRole('heading', { name: tr('setupDoneTitle') })).toBeVisible();
    expect(sent[1]).toMatchObject({ starter_categories: false });
});

test('shows the wizard even with the session of a previous installation', async ({ page }) => {
    // The API of a server not installed (the routes of the wizard are added after: they win)
    await page.route('**/api/**', route => route.fulfill({ status: 503, json: { error: 'Carbure is not installed yet', code: 503, setup: true } }));
    await notInstalled(page);
    // Same address, token of an instance that was since reinstalled from scratch
    await page.addInitScript(() => {
        localStorage.setItem('authToken', 'token-of-a-previous-installation');
        localStorage.setItem('username', 'admin');
    });
    await page.goto('/portal/');

    await expect(page.getByRole('heading', { name: tr('setupTitle') })).toBeVisible();
    await expect(page.locator('.user-menu-name')).toHaveCount(0);
    expect(await page.evaluate(() => localStorage.getItem('authToken'))).toBeNull();
});

test('shows the login screen once installed', async ({ page }) => {
    await page.goto('/portal/');

    await expect(page.getByRole('button', { name: tr('loginButton') })).toBeVisible();
    await expect(page.getByRole('heading', { name: tr('setupTitle') })).toHaveCount(0);
});
