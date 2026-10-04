// Banners of the administrators: database update and weak session secret.
// The e2e database is up to date: the state of the schema is simulated.
const { test, expect, tr } = require('../fixtures');

/**
 * Simulates a database with a pending migration and the example session secret
 * @param {import('@playwright/test').Page} page
 */
async function outdated(page) {
    await page.route('**/api/system/schema', route => route.fulfill({
        json: { version: '2026-10-13_base', pending: ['2026-11-01_example'], weakJwtSecret: true },
    }));
    await page.route('**/api/system/migrate', route => route.fulfill({
        json: { version: '2026-11-01_example', applied: ['2026-11-01_example'] },
    }));
}

test('an administrator updates the database from the banner', async ({ page, adminPage }) => {
    await outdated(page);
    await page.reload();

    const banner = page.locator('.schema-banner').filter({ hasText: tr('schemaUpdateAvailable', { count: 1 }) });
    await expect(banner).toBeVisible();
    await expect(page.locator('.schema-banner').filter({ hasText: tr('weakJwtSecret') })).toBeVisible();

    await banner.getByRole('button', { name: tr('schemaUpdate') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('migrationDone', { version: '2026-11-01_example' }));
    await expect(banner).toHaveCount(0);
});

test('a user does not see the banners of the administrators', async ({ page, userPage }) => {
    await outdated(page);
    await page.reload();

    await expect(page.locator('.transaction-item').first()).toBeVisible();
    await expect(page.locator('.schema-banner')).toHaveCount(0);
});

test('the up-to-date e2e database shows no banner', async ({ adminPage: page }) => {
    await expect(page.locator('.transaction-item').first()).toBeVisible();
    await expect(page.locator('.schema-banner')).toHaveCount(0);
});
