// OAuth 2.1 of the MCP server, as an AI agent that only takes the URL of the server
// (Claude web, Desktop, mobile) goes through it: discovery, registration, consent of
// the user in the portal, code exchange with PKCE, then calls to the MCP server.
const crypto = require('crypto');
const { test, expect, tr, openTab, USERS } = require('../fixtures');

/** Callback of the agent: never reached, the browser navigation to it is intercepted */
const CALLBACK = 'http://localhost:9/callback';
const VERIFIER = crypto.randomBytes(32).toString('base64url');
const CHALLENGE = crypto.createHash('sha256').update(VERIFIER).digest('base64url');

/**
 * Enables the MCP server (an administrator does it in the portal) and registers an agent
 * @param {import('@playwright/test').APIRequestContext} api - Requests as the administrator
 * @param {import('@playwright/test').APIRequestContext} request - Anonymous requests
 * @returns {Promise<string>} client_id
 */
async function registerAgent(api, request) {
    await api.put('/api/mcp/settings', { data: { enabled: true } });
    const response = await request.post('/api/oauth/register', { data: { redirect_uris: [CALLBACK], client_name: 'Claude', token_endpoint_auth_method: 'none' } });
    expect(response.status()).toBe(201);
    return (await response.json()).client_id;
}

/**
 * Opens the authorization URL of the agent, logs in as marie in the portal, and
 * answers the consent; returns the parameters of the callback
 * @param {import('@playwright/test').Page} page
 * @param {string} clientId
 * @param {string} answer - Label of the button
 * @returns {Promise<URLSearchParams>}
 */
async function authorize(page, clientId, answer) {
    let callback;
    await page.route(`${CALLBACK}**`, route => {
        callback = new URL(route.request().url());
        return route.fulfill({ body: 'Back to the agent' });
    });

    const query = new URLSearchParams({
        response_type: 'code', client_id: clientId, redirect_uri: CALLBACK, state: 'state-123',
        code_challenge: CHALLENGE, code_challenge_method: 'S256',
    });
    await page.goto(`/api/oauth/authorize?${query}`);

    // The portal, without the request in the address bar, asks to log in
    await expect(page.getByRole('button', { name: tr('loginButton') })).toBeVisible();
    expect(page.url()).not.toContain('oauth_request');
    await page.getByLabel(tr('username')).fill(USERS.marie.username);
    await page.getByLabel(tr('password')).fill(USERS.marie.password);
    await page.getByRole('button', { name: tr('loginButton') }).click();

    const consent = page.getByRole('dialog', { name: tr('oauthTitle', { name: 'Claude' }) });
    await expect(consent).toContainText(tr('oauthRedirect', { host: 'localhost' }));
    await consent.getByRole('button', { name: answer }).click();

    await expect.poll(() => callback && callback.toString()).toBeTruthy();
    expect(callback.searchParams.get('state')).toBe('state-123');
    return callback.searchParams;
}

test('an agent finds how to connect from the URL of the MCP server', async ({ request, baseURL }) => {
    const unauthorized = await request.post('/api/mcp', { data: { jsonrpc: '2.0', id: 1, method: 'tools/list' } });
    // Disabled by default: an administrator enables it
    expect(unauthorized.status()).toBe(403);

    const origin = new URL(baseURL).origin;
    const resource = await (await request.get('/.well-known/oauth-protected-resource')).json();
    expect(resource).toMatchObject({ resource: `${origin}/api/mcp`, authorization_servers: [origin] });

    const server = await (await request.get('/.well-known/oauth-authorization-server')).json();
    expect(server).toMatchObject({
        issuer: origin,
        authorization_endpoint: `${origin}/api/oauth/authorize`,
        token_endpoint: `${origin}/api/oauth/token`,
        registration_endpoint: `${origin}/api/oauth/register`,
        code_challenge_methods_supported: ['S256'],
    });
});

test('the user allows the agent, which then reads the data with its token', async ({ page, api, request }) => {
    const clientId = await registerAgent(api, request);

    // Without token, the MCP server tells where the metadata is
    const challenge = await request.post('/api/mcp', { data: { jsonrpc: '2.0', id: 1, method: 'tools/list' } });
    expect(challenge.status()).toBe(401);
    expect(challenge.headers()['www-authenticate']).toContain('resource_metadata="');

    const callback = await authorize(page, clientId, tr('oauthAllow'));
    const code = callback.get('code');
    expect(code).toBeTruthy();

    // Code exchange, as a form, with the PKCE verifier
    const tokenResponse = await request.post('/api/oauth/token', {
        form: { grant_type: 'authorization_code', code, redirect_uri: CALLBACK, client_id: clientId, code_verifier: VERIFIER },
    });
    expect(tokenResponse.ok()).toBeTruthy();
    const tokens = await tokenResponse.json();
    expect(tokens.token_type).toBe('Bearer');

    const tools = await request.post('/api/mcp', {
        headers: { Authorization: `Bearer ${tokens.access_token}` },
        data: { jsonrpc: '2.0', id: 1, method: 'tools/call', params: { name: 'list_categories', arguments: {} } },
    });
    expect(tools.ok()).toBeTruthy();
    expect(JSON.stringify(await tools.json())).toContain('Alimentation');

    // A new access token with the refresh token
    const refreshed = await request.post('/api/oauth/token', {
        form: { grant_type: 'refresh_token', refresh_token: tokens.refresh_token, client_id: clientId },
    });
    expect((await refreshed.json()).access_token).toMatch(/^cbt_/);

    // The access is listed with the tokens of the user, who can revoke it
    await page.goto('/portal/');
    await openTab(page, tr('tabAgents'));
    await expect(page.locator('.device-item').filter({ hasText: 'Claude' })).toBeVisible();
});

test('the user refuses the agent', async ({ page, api, request }) => {
    const clientId = await registerAgent(api, request);

    const callback = await authorize(page, clientId, tr('oauthDeny'));
    expect(callback.get('error')).toBe('access_denied');
    expect(callback.get('code')).toBeNull();
});

test('the AI agent tab gives the URL to paste in Claude', async ({ userPage: page, baseURL }) => {
    await openTab(page, tr('tabAgents'));

    const card = page.locator('section').filter({ has: page.getByRole('heading', { name: tr('oauthConnectTitle') }) });
    await expect(card.locator('.copy-field code')).toHaveText(`${new URL(baseURL).origin}/api/mcp`);
});
