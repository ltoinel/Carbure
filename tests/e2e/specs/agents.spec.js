// Agent tab: MCP server switch (administrators) and access tokens of the AI agents
const { test, expect, tr, openTab } = require('../fixtures');

test('an administrator enables MCP, creates a token that works, then revokes it', async ({ adminPage: page, request }) => {
    await openTab(page, tr('tabAgents'));
    const add = page.getByRole('button', { name: tr('addApiToken') });
    await expect(add).toBeDisabled();
    await expect(page.locator('.transactions-hint')).toHaveText(tr('noApiTokens'));

    await page.locator('label.toggle-switch').click();
    await expect(page.locator('.toast')).toHaveText(tr('mcpEnabledToast'));
    await expect(page.getByRole('switch', { name: tr('mcpEnabled') })).toBeChecked();

    await add.click();
    const modal = page.getByRole('dialog', { name: tr('addApiToken') });
    await modal.getByLabel(new RegExp(tr('apiTokenName'))).fill('Claude');
    await modal.getByRole('button', { name: tr('create') }).click();

    await expect(modal.getByRole('alert')).toContainText(tr('apiTokenShownOnce'));
    const token = (await modal.locator('.copy-field code').first().textContent()).trim();
    expect(token.length).toBeGreaterThan(20);
    // Ready-made configuration of each agent, with the token
    await expect(modal.locator('.agent-panel')).toContainText(`claude mcp add --transport http carbure`);
    await modal.getByRole('tab', { name: 'Cursor' }).click();
    await expect(modal.locator('.agent-panel')).toContainText(`"Authorization": "Bearer ${token}"`);
    await modal.getByRole('button', { name: tr('done') }).click();

    // The token opens the MCP server
    const mcp = await request.post('/api/mcp', {
        headers: { Authorization: `Bearer ${token}` },
        data: { jsonrpc: '2.0', id: 1, method: 'tools/list' },
    });
    expect(mcp.ok()).toBeTruthy();
    expect(await mcp.text()).toContain('get_budget');

    const item = page.locator('.device-item').filter({ hasText: 'Claude' });
    await expect(item).toContainText(tr('apiTokenNeverUsed'));
    await item.getByRole('button', { name: `${tr('revokeApiToken')} Claude` }).click();
    await expect(page.locator('.toast')).toHaveText(tr('apiTokenRevoked'));
    await expect(page.locator('.device-item')).toHaveCount(0);

    const revoked = await request.post('/api/mcp', {
        headers: { Authorization: `Bearer ${token}` },
        data: { jsonrpc: '2.0', id: 1, method: 'tools/list' },
    });
    expect(revoked.ok()).toBeFalsy();
});

test('a user sees the state of the MCP server without changing it', async ({ userPage: page }) => {
    await openTab(page, tr('tabAgents'));

    await expect(page.getByRole('switch')).toHaveCount(0);
    await expect(page.locator('.mcp-switch-row')).toContainText(tr('mcpOff'));
    await expect(page.locator('.mcp-switch-row')).toContainText(tr('mcpOffHelp'));
    await expect(page.getByRole('button', { name: tr('addApiToken') })).toBeDisabled();
});

test('the token in the URL is refused until an administrator allows it', async ({ adminPage: page, request, api }) => {
    await api.put('/api/mcp/settings', { data: { enabled: true } });
    const token = (await (await api.post('/api/token', { data: { name: 'Old agent' } })).json()).token;
    const ping = () => request.post(`/api/mcp?token=${token}`, { data: { jsonrpc: '2.0', id: 1, method: 'ping' } });

    // Off by default: refused, with what to do instead
    const refused = await ping();
    expect(refused.status()).toBe(401);
    expect((await refused.json()).error).toContain('The token in the URL is disabled');

    await openTab(page, tr('tabAgents'));
    const allow = page.getByRole('switch', { name: tr('mcpUrlToken') });
    await expect(allow).not.toBeChecked();
    await expect(page.locator('.mcp-url-token')).toContainText(tr('mcpUrlTokenHelp'));
    await expect(page.locator('.mcp-url-token-warning')).toHaveCount(0);
    // The administrator sees that an agent still gives its token in the URL
    await expect(page.locator('.mcp-url-token-used')).toBeVisible();

    await page.locator('.mcp-url-token label.toggle-switch').click();
    await expect(page.locator('.toast')).toHaveText(tr('mcpUrlTokenOnToast'));
    await expect(allow).toBeChecked();
    await expect(page.locator('.mcp-url-token-warning')).toHaveText(new RegExp(tr('mcpUrlTokenWarning').slice(0, 30)));

    // Allowed, and announced as deprecated
    const accepted = await ping();
    expect(accepted.ok()).toBeTruthy();
    expect(accepted.headers().deprecation).toBeTruthy();
});
