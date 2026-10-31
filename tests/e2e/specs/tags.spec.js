// Tags of a project limited in time (Tags tab), set on the transactions by hand
const { test, expect, tr, openTab, euros } = require('../fixtures');

/** Row of a transaction, by its label */
const row = (page, label) => page.locator('.transaction-item').filter({ hasText: label });

/** Creates a tag in the Tags tab */
async function createTag(page, name, end = '') {
    await openTab(page, tr('tabTags'));
    await page.getByRole('button', { name: tr('tagNew') }).click();
    const modal = page.getByRole('dialog', { name: tr('tagNew') });
    await modal.getByLabel(tr('tagName')).fill(name);
    if (end) {
        await modal.getByLabel(tr('tagStart')).fill('2020-01-01');
        await modal.getByLabel(tr('tagEnd')).fill(end);
    }
    await modal.getByRole('button', { name: tr('save') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('tagSaved'));
    // Gone before going on: the message covers the bottom of the modals
    await expect(page.locator('.toast')).toHaveCount(0);
}

test('tags transactions of a project, then shows its summary', async ({ userPage: page }) => {
    await createTag(page, '#Voyage-Italie');
    await expect(page.locator('.tag-list-active .tag-name')).toHaveText('#voyage-italie');

    // From the detail of a transaction
    await openTab(page, tr('tabTransactions'));
    await row(page, 'CB RESTAURANT LE PETIT ZINC').getByRole('button', { name: new RegExp(tr('transactionDetail')) }).click();
    const detail = page.getByRole('dialog', { name: tr('transactionDetail') });
    await detail.locator('#detail-tag').selectOption({ label: '#voyage-italie' });
    await detail.getByRole('button', { name: tr('tagAdd') }).click();
    await expect(detail.locator('.tag-chip')).toContainText('#voyage-italie');
    await expect(detail.getByRole('button', { name: tr('tagRemoveOf', { name: 'voyage-italie' }) })).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(row(page, 'CB RESTAURANT LE PETIT ZINC').locator('.tag-chip')).toHaveText('#voyage-italie');

    // A selection of the list
    await page.getByRole('button', { name: tr('tagSelect'), exact: true }).click();
    await page.getByLabel(tr('tagSelectTransaction', { label: 'CB SUPERMARCHE CASINO' })).check();
    await page.getByLabel(tr('tagSelectTransaction', { label: 'CB BOULANGERIE PAUL' })).check();
    await expect(page.locator('.tag-selection-count')).toHaveText(tr('tagSelected', { count: 2 }));
    await page.locator('#tag-selection').selectOption({ label: '#voyage-italie' });
    await page.locator('.tag-selection-bar').getByRole('button', { name: tr('tagAdd') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('tagAddedToSelection', { count: 2, name: 'voyage-italie' }));
    await expect(page.locator('.tag-selection-bar')).toHaveCount(0);

    // Found by its name in the search
    await page.locator('.transaction-search input').fill('#voyage-italie');
    await expect(page.locator('.transaction-item')).toHaveCount(3);

    // Its summary: net cost, by category, its transactions
    await openTab(page, tr('tabTags'));
    await page.getByRole('button', { name: tr('tagSummaryOf', { name: 'voyage-italie' }) }).click();
    const summary = page.locator('.tag-summary');
    await expect(summary.locator('.tag-summary-total strong')).toHaveText(euros(-134.2));
    await expect(summary.locator('.tag-category')).toHaveCount(3);
    await expect(summary.locator('.tag-transaction')).toHaveCount(3);
    await expect(summary.getByRole('img', { name: tr('tagChartLabel', { name: 'voyage-italie' }) })).toBeVisible();
    // The category of the transactions did not change
    await expect(summary.locator('.tag-transaction').filter({ hasText: 'CB RESTAURANT LE PETIT ZINC' }).locator('.category-chip')).toHaveText('Restaurant');
});

test('an archived tag is no more offered, its summary stays readable', async ({ userPage: page, api }) => {
    await createTag(page, 'ete-2020', '2020-08-31');
    await expect(page.locator('.tag-list-archived .tag-name')).toHaveText('#ete-2020');

    await openTab(page, tr('tabTransactions'));
    // No active tag: no selection, nothing offered in the detail
    await expect(page.getByRole('button', { name: tr('tagSelect'), exact: true })).toHaveCount(0);
    await row(page, 'CB RESTAURANT LE PETIT ZINC').getByRole('button', { name: new RegExp(tr('transactionDetail')) }).click();
    await expect(page.getByRole('dialog', { name: tr('transactionDetail') })).toContainText(tr('tagNoneActive'));
    await page.keyboard.press('Escape');

    await openTab(page, tr('tabTags'));
    await page.getByRole('button', { name: tr('tagSummaryOf', { name: 'ete-2020' }) }).click();
    await expect(page.locator('.tag-summary')).toContainText(tr('tagArchived'));
    await expect(page.locator('.tag-summary')).toContainText(tr('tagNoTransaction'));
    expect((await (await api.get('/api/tag')).json())[0]).toMatchObject({ name: 'ete-2020', active: false });
});

test('the add buttons of the Tags and Transactions tabs are round + buttons (#419)', async ({ adminPage: page }) => {
    const round = async button => {
        await expect(button).toBeVisible();
        await expect(button).toHaveClass(/btn-add-icon/);
        const box = await button.boundingBox();
        expect(Math.round(box.width)).toBe(Math.round(box.height));
    };
    await round(page.locator('.transactions-toolbar').getByRole('button', { name: tr('manualTransactionTitle') }));
    await openTab(page, tr('tabTags'));
    await round(page.getByRole('button', { name: tr('tagNew') }));
});
