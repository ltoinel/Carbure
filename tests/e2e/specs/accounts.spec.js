// Accounts tab (administrators): followed bank accounts and synchronization (fake woob)
const { test, expect, tr, openTab, escapeRegExp } = require('../fixtures');

/** Row of a followed account, by its bank id (account_number@bank) */
const account = (page, bankId) => page.locator('.account-item').filter({ has: page.locator('code', { hasText: new RegExp(`^${escapeRegExp(bankId)}$`) }) });

test('lists the followed accounts with their last synchronization', async ({ adminPage: page }) => {
    await openTab(page, tr('tabAccounts'));

    await expect(page.locator('.accounts-list .account-item')).toHaveCount(2);
    const bnp = account(page, '00012345678@bnp');
    await expect(bnp.locator('.account-name')).toContainText('BNP ···5678');
    await expect(bnp.locator('.account-name')).toContainText(tr('accountAddedBy', { user: 'admin' }));
    await expect(bnp.locator('.account-sync-state')).toHaveText(tr('lastSync_OK'));
    await expect(account(page, 'fail@bank').locator('.account-sync-state')).toHaveText(tr('lastSyncNever'));
});

test('synchronizes one account', async ({ adminPage: page }) => {
    await openTab(page, tr('tabAccounts'));
    await account(page, '00012345678@bnp').getByRole('button', { name: tr('syncAccount') }).click();

    // Only this account: the other one (fail@bank) would end in error
    const status = page.locator('.sync-status');
    await expect(status).toContainText(tr('syncStatus_success'));
    await expect(page.locator('.sync-account')).toHaveCount(1);
    await expect(page.locator('.sync-account .step-ok')).not.toHaveCount(0);
    await expect(page.locator('.sync-step-count').first()).toContainText(tr('syncStepResult', { count: 1, created: 1 }));

    await page.getByRole('button', { name: tr('syncShowLog') }).click();
    await expect(page.locator('.sync-log')).toContainText('Synchronization complete');

    // The new transactions are in the month
    await openTab(page, tr('tabTransactions'));
    // The card payment of the fake woob, besides the one of the data set
    await expect(page.locator('.transaction-item').filter({ hasText: 'BOULANGERIE' })).toHaveCount(2);
});

test('reports a synchronization error', async ({ adminPage: page }) => {
    await openTab(page, tr('tabAccounts'));
    await account(page, 'fail@bank').getByRole('button', { name: tr('syncAccount') }).click();

    await expect(page.locator('.sync-status')).toContainText(tr('syncStatus_error'));
    await expect(page.locator('.sync-step-error').first()).toContainText('iter_accounts');
    await expect(account(page, 'fail@bank').locator('.account-sync-state')).toHaveText(tr('lastSync_ERROR'));
});

test('finds the accounts of a bank in woob and follows one', async ({ adminPage: page }) => {
    await openTab(page, tr('tabAccounts'));
    await page.getByRole('button', { name: tr('addAccountTitle') }).click();

    const modal = page.getByRole('dialog', { name: tr('addAccountTitle') });
    await modal.getByRole('button', { name: tr('discoverAccounts') }).click();
    const discovered = modal.locator('.discovered .account-item');
    await expect(discovered).toHaveCount(2);
    await expect(discovered.filter({ hasText: 'Compte chèques' })).toContainText(tr('accountFollowed'));

    await discovered.filter({ hasText: 'Livret A' }).getByRole('button', { name: tr('followAccount') }).click();
    await expect(modal.getByLabel(new RegExp(tr('accountNumber')))).toHaveValue('00087654321');
    await expect(modal.getByLabel(new RegExp(escapeRegExp(tr('accountBank'))))).toHaveValue('bnp');
    // Named after its label in woob
    await expect(modal.getByLabel(tr('accountLabel'))).toHaveValue('Livret A');
    await modal.getByRole('button', { name: tr('save') }).click();

    await expect(page.locator('.toast')).toHaveText(tr('accountAdded'));
    await expect(account(page, '00087654321@bnp').locator('.account-name')).toContainText('Livret A');
});

test('names an account, shown instead of the bank and the end of its number', async ({ adminPage: page }) => {
    await openTab(page, tr('tabAccounts'));
    const bnp = account(page, '00012345678@bnp');
    await bnp.getByRole('button', { name: tr('editAccount') }).click();

    let modal = page.getByRole('dialog', { name: tr('editAccount') });
    await expect(modal.getByLabel(tr('accountLabel'))).toHaveValue('');
    await modal.getByLabel(tr('accountLabel')).fill('Compte joint');
    await modal.getByRole('button', { name: tr('save') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('accountUpdated'));
    await expect(bnp.locator('.account-name')).toContainText('Compte joint');
    await expect(bnp.locator('.account-name')).not.toContainText('BNP ···5678');

    // Also in the synchronization progress
    await bnp.getByRole('button', { name: tr('syncAccount') }).click();
    await expect(page.locator('.sync-status')).toContainText(tr('syncStatus_success'));
    await expect(page.locator('.sync-account-name')).toContainText('Compte joint');

    // Emptied: back to the bank and the end of the number
    await bnp.getByRole('button', { name: tr('editAccount') }).click();
    modal = page.getByRole('dialog', { name: tr('editAccount') });
    await expect(modal.getByLabel(tr('accountLabel'))).toHaveValue('Compte joint');
    await modal.getByLabel(tr('accountLabel')).fill('');
    await modal.getByRole('button', { name: tr('save') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('accountUpdated'));
    await expect(bnp.locator('.account-name')).toContainText('BNP ···5678');
});

test('approves the strong authentication asked by a bank', async ({ adminPage: page }) => {
    // Account of the fake woob that only answers in interactive mode (on a terminal)
    await openTab(page, tr('tabAccounts'));
    await page.getByRole('button', { name: tr('addAccountTitle') }).click();
    const modal = page.getByRole('dialog', { name: tr('addAccountTitle') });
    await modal.locator('#field-2').selectOption('__other');
    await modal.getByPlaceholder(tr('accountBankPlaceholder')).fill('bank');
    await modal.getByLabel(new RegExp(tr('accountNumber'))).fill('2fa');
    await modal.getByRole('button', { name: tr('save') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('accountAdded'));

    // A normal synchronization fails: woob needs the interactive mode
    await account(page, '2fa@bank').getByRole('button', { name: tr('syncAccount') }).click();
    await expect(page.locator('.sync-status')).toContainText(tr('syncStatus_error'));
    await expect(page.locator('.sync-step-error')).toContainText(tr('sync2faHelp'));

    // The account stays flagged (e.g. after a failed scheduled synchronization)
    const row = account(page, '2fa@bank');
    await expect(row.locator('.account-sync-state')).toHaveText(tr('lastSync2fa'));

    await row.getByRole('button', { name: tr('sync2faButton') }).click();
    await expect(page.locator('.sync-status')).toContainText(tr('syncStatus_success'));
    await expect(page.getByRole('button', { name: tr('sync2faButton') })).toHaveCount(0);
    await page.getByRole('button', { name: tr('syncShowLog') }).click();
    await expect(page.locator('.sync-log')).toContainText('Bank message 2fa@bank: Veuillez valider la connexion dans votre application bancaire.');
    await expect(account(page, '2fa@bank').locator('.account-sync-state')).toHaveText(tr('lastSync_OK'));
});

test('adds an account of a bank typed by hand, modifies then deletes it', async ({ adminPage: page }) => {
    await openTab(page, tr('tabAccounts'));
    await page.getByRole('button', { name: tr('addAccountTitle') }).click();

    let modal = page.getByRole('dialog', { name: tr('addAccountTitle') });
    await modal.locator('#field-2').selectOption('__other');
    await modal.getByPlaceholder(tr('accountBankPlaceholder')).fill('mabanque');
    await modal.getByLabel(new RegExp(tr('accountNumber'))).fill('FR7600001111');
    await modal.getByRole('button', { name: tr('save') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('accountAdded'));
    await expect(account(page, 'FR7600001111@mabanque')).toBeVisible();

    await account(page, 'FR7600001111@mabanque').getByRole('button', { name: tr('editAccount') }).click();
    modal = page.getByRole('dialog', { name: tr('editAccount') });
    await modal.getByLabel(new RegExp(tr('accountNumber'))).fill('FR7600002222');
    await modal.getByRole('button', { name: tr('save') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('accountUpdated'));
    await expect(account(page, 'FR7600002222@mabanque')).toBeVisible();

    await account(page, 'FR7600002222@mabanque').getByRole('button', { name: tr('deleteAccount') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('accountDeleted'));
    await expect(account(page, 'FR7600002222@mabanque')).toHaveCount(0);
});

test('asks the settings of a bank not configured in woob yet', async ({ adminPage: page }) => {
    await openTab(page, tr('tabAccounts'));
    await page.getByRole('button', { name: tr('addAccountTitle') }).click();

    const modal = page.getByRole('dialog', { name: tr('addAccountTitle') });
    await modal.locator('#field-2').selectOption('boursorama');
    const setup = modal.locator('fieldset.bank-setup');
    await expect(setup).toBeVisible();
    await expect(setup.getByLabel('Numéro client *')).toBeVisible();
    await expect(setup.getByLabel('Code secret *')).toHaveAttribute('type', 'password');
});
