/**
 * AI Agents Module
 *
 * AI agents tab: the MCP server of Carbure (enabled or not by an administrator),
 * the access tokens of the user, and how to connect the main AI agents.
 *
 * @module agentsModule
 */

/**
 * Configuration of each AI agent for a token (Claude web, Desktop and mobile need none:
 * they connect with OAuth, from the URL of the server shown in the AI agent tab)
 * @param {string} url - URL of the MCP server
 * @param {string} token - Access token
 * @returns {Array<{id: string, name: string, how: string, code: string}>}
 */
export function agentConfigurations(url, token) {
    const header = `Authorization: Bearer ${token}`;
    const json = value => JSON.stringify(value, null, 2);
    return [
        {
            id: 'claude-code',
            name: 'Claude Code',
            how: 'agentHow_claudeCode',
            code: `claude mcp add --transport http carbure ${url} --header "${header}"`
        },
        {
            id: 'chatgpt',
            name: 'ChatGPT',
            how: 'agentHow_chatgpt',
            // OAuth: ChatGPT only takes the URL and asks the user to authorize it in the portal
            code: url
        },
        {
            id: 'cursor',
            name: 'Cursor',
            how: 'agentHow_cursor',
            code: json({ mcpServers: { carbure: { url, headers: { Authorization: `Bearer ${token}` } } } })
        },
        {
            id: 'vscode',
            name: 'VS Code (Copilot)',
            how: 'agentHow_vscode',
            code: json({ servers: { carbure: { type: 'http', url, headers: { Authorization: `Bearer ${token}` } } } })
        },
        {
            id: 'gemini',
            name: 'Gemini CLI',
            how: 'agentHow_gemini',
            code: `gemini mcp add --transport http --header "${header}" carbure ${url}`
        }
    ];
}

/**
 * Creates the AI agents module mixin for Vue components
 * @param {Function} getApiService - Function that returns the API service instance
 * @returns {Object} Vue mixin with the AI agents data and methods
 */
export function createAgentsModule(getApiService) {
    return {
        data() {
            return {
                mcpEnabled: false,
                switchingMcp: false,
                // API token accepted in the URL (?token=, deprecated): off by default
                mcpUrlToken: false,
                // Last day an agent gave its token in the URL (Y-m-d), null if never
                mcpUrlTokenLastUsed: null,
                // Agent whose configuration is shown after creating a token
                selectedAgent: 'claude-code'
            };
        },

        computed: {
            /**
             * Configurations of the agents for the token just created
             * @returns {Array}
             */
            agentConfigs() {
                return this.newApiToken ? agentConfigurations(this.mcpUrl(), this.newApiToken.token) : [];
            },

            /**
             * Configuration of the selected agent
             * @returns {Object|null}
             */
            selectedAgentConfig() {
                return this.agentConfigs.find(a => a.id === this.selectedAgent) || this.agentConfigs[0] || null;
            }
        },

        methods: {
            /**
             * Loads the state of the MCP server and the tokens of the user
             * @returns {Promise<void>}
             */
            async loadAgents() {
                try {
                    this.applyMcpSettings(await getApiService().fetchMcpSettings());
                } catch (error) {
                    this.applyMcpSettings({});
                }
                this.loadApiTokens();
            },

            /**
             * Keeps the settings of the MCP server answered by the API
             * @param {Object} settings - {enabled, url_token, url_token_last_used}
             */
            applyMcpSettings(settings) {
                this.mcpEnabled = !!settings.enabled;
                this.mcpUrlToken = !!settings.url_token;
                this.mcpUrlTokenLastUsed = settings.url_token_last_used || null;
            },

            /**
             * Allows or refuses the API token in the URL (administrators)
             * @param {Event} [event] - Change of the switch: shown again as saved when refused
             * @returns {Promise<void>}
             */
            async toggleMcpUrlToken(event) {
                this.switchingMcp = true;
                try {
                    this.applyMcpSettings(await getApiService().updateMcpUrlToken(!this.mcpUrlToken));
                    this.showToast(this.t(this.mcpUrlToken ? 'mcpUrlTokenOnToast' : 'mcpUrlTokenOffToast'));
                } catch (error) {
                    this.showToast(this.apiErrorText(error));
                } finally {
                    this.switchingMcp = false;
                    if (event && event.target) {
                        event.target.checked = this.mcpUrlToken;
                    }
                }
            },

            /**
             * Enables or disables the MCP server (administrators)
             * @param {Event} [event] - Change of the switch: shown again as saved when refused
             * @returns {Promise<void>}
             */
            async toggleMcp(event) {
                this.switchingMcp = true;
                try {
                    this.applyMcpSettings(await getApiService().updateMcpSettings(!this.mcpEnabled));
                    this.showToast(this.t(this.mcpEnabled ? 'mcpEnabledToast' : 'mcpDisabledToast'));
                } catch (error) {
                    this.showToast(this.apiErrorText(error));
                } finally {
                    this.switchingMcp = false;
                    // Vue does not patch a value that did not change (#342)
                    if (event && event.target) {
                        event.target.checked = this.mcpEnabled;
                    }
                }
            }
        }
    };
}
