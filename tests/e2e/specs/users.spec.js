// Users tab (administrators): list, add, modify, unlock, delete
const { test, expect, tr, openTab } = require('../fixtures');

/** Row of a user, by username */
const userRow = (page, username) => page.locator('.users-table tbody tr').filter({ has: page.locator('.user-username', { hasText: new RegExp(`(^|\\s)${username}\\s*$`) }) });

test('lists the users with their role', async ({ adminPage: page }) => {
    await openTab(page, tr('tabUsers'));

    await expect(page.locator('.users-table tbody tr')).toHaveCount(2);
    await expect(userRow(page, 'admin')).toContainText(tr('role_admin'));
    await expect(userRow(page, 'marie')).toContainText(tr('role_user'));
    await expect(userRow(page, 'marie')).toContainText('marie@example.com');
});

test('adds a user who can log in', async ({ adminPage: page, request }) => {
    await openTab(page, tr('tabUsers'));
    await page.getByRole('button', { name: tr('addUser') }).click();

    const modal = page.getByRole('dialog', { name: tr('addUser') });
    await modal.getByLabel(`${tr('username')} *`).fill('paul');
    await modal.getByLabel(`${tr('email')} *`).fill('paul@example.com');
    await modal.getByLabel(new RegExp(`^${tr('password')}`)).fill('paul-password-1');
    await modal.getByLabel(tr('firstname'), { exact: true }).fill('Paul');
    await modal.getByRole('button', { name: tr('save') }).click();

    await expect(page.locator('.toast')).toHaveText(tr('userCreated'));
    await expect(userRow(page, 'paul')).toContainText('Paul');

    const login = await request.post('/api/user/login', { data: { username: 'paul', password: 'paul-password-1' } });
    expect(login.ok()).toBeTruthy();
});

test('modifies a user and makes them an administrator', async ({ adminPage: page }) => {
    await openTab(page, tr('tabUsers'));
    await page.getByRole('button', { name: `${tr('editUser')} marie` }).click();

    const modal = page.getByRole('dialog', { name: tr('editUser') });
    await expect(modal.getByLabel(`${tr('username')} *`)).toBeDisabled();
    await modal.getByLabel(tr('lastname'), { exact: true }).fill('Durand');
    await modal.getByLabel(tr('userRole'), { exact: true }).selectOption({ label: tr('role_admin') });
    await modal.getByRole('button', { name: tr('save') }).click();

    await expect(page.locator('.toast')).toHaveText(tr('userUpdated'));
    await expect(userRow(page, 'marie')).toContainText('Durand');
    await expect(userRow(page, 'marie')).toContainText(tr('role_admin'));
});

test('unlocks a user locked after failed logins', async ({ adminPage: page, request }) => {
    for (let i = 0; i < 5; i++) {
        await request.post('/api/user/login', { data: { username: 'marie', password: 'wrong' } });
    }
    const locked = await request.post('/api/user/login', { data: { username: 'marie', password: 'marie-password' } });
    expect(locked.status()).toBe(423);

    await openTab(page, tr('tabUsers'));
    await expect(userRow(page, 'marie').locator('.user-locked')).toBeVisible();
    await page.getByRole('button', { name: `${tr('unlockUser')} marie` }).click();
    await expect(userRow(page, 'marie').locator('.user-locked')).toHaveCount(0);

    const unlocked = await request.post('/api/user/login', { data: { username: 'marie', password: 'marie-password' } });
    expect(unlocked.ok()).toBeTruthy();
});

test('deletes a user', async ({ adminPage: page }) => {
    await openTab(page, tr('tabUsers'));
    await page.getByRole('button', { name: `${tr('deleteUser')} marie` }).click();

    await expect(page.locator('.toast')).toHaveText(tr('userDeleted'));
    await expect(userRow(page, 'marie')).toHaveCount(0);
});

test('closes the sessions of a user, who goes back to the login screen', async ({ adminPage: page, request, browser }) => {
    // Marie is on the portal in her own browser
    const marie = await (await browser.newContext()).newPage();
    const token = (await (await request.post('/api/user/login', { data: { username: 'marie', password: 'marie-password' } })).json()).token;
    await marie.addInitScript(([jwt]) => {
        localStorage.setItem('authToken', jwt);
        localStorage.setItem('username', 'marie');
    }, [token]);
    await marie.goto('/portal/');
    await expect(marie.locator('.user-menu-name')).toHaveText('marie');

    await openTab(page, tr('tabUsers'));
    await page.getByRole('button', { name: `${tr('signOutUser')} marie` }).click();
    const dialog = page.getByRole('dialog', { name: tr('signOutUser') });
    await expect(dialog.locator('#confirm-dialog-message')).toHaveText(tr('signOutUserConfirm', { username: 'marie' }));
    await expect(dialog.locator('#confirm-dialog-warning')).toHaveText(tr('signOutUserWarning'));
    await dialog.getByRole('button', { name: tr('signOutUser') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('signOutUserDone', { username: 'marie' }));

    // Her next call is refused: login screen, with the reason
    await marie.reload();
    await expect(marie.getByRole('alert')).toContainText(tr('sessionExpired'));
    await expect(marie.getByRole('button', { name: tr('loginButton') })).toBeVisible();
    await marie.context().close();
    // The administrator keeps his own session
    await page.reload();
    await expect(page.locator('.user-menu-name')).toHaveText('admin');
});

test('administrators who close their own sessions from the list stay signed in', async ({ adminPage: page }) => {
    const before = await page.evaluate(() => localStorage.getItem('authToken'));
    await openTab(page, tr('tabUsers'));
    await page.getByRole('button', { name: `${tr('signOutUser')} admin` }).click();

    // Their own account: the wording of the profile
    const dialog = page.getByRole('dialog', { name: tr('signOutEverywhere') });
    await dialog.getByRole('button', { name: tr('signOutEverywhere') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('signOutEverywhereDone'));
    expect(await page.evaluate(() => localStorage.getItem('authToken'))).not.toBe(before);
    await page.reload();
    await expect(page.locator('.user-menu-name')).toHaveText('admin');
});
