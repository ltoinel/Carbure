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
            code: `${url}?token=${token}`
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
                    this.mcpEnabled = !!(await getApiService().fetchMcpSettings()).enabled;
                } catch (error) {
                    this.mcpEnabled = false;
                }
                this.loadApiTokens();
            },

            /**
             * Enables or disables the MCP server (administrators)
             * @returns {Promise<void>}
             */
            async toggleMcp() {
                this.switchingMcp = true;
                try {
                    this.mcpEnabled = !!(await getApiService().updateMcpSettings(!this.mcpEnabled)).enabled;
                    this.showToast(this.t(this.mcpEnabled ? 'mcpEnabledToast' : 'mcpDisabledToast'));
                } catch (error) {
                    this.showToast(error.message);
                } finally {
                    this.switchingMcp = false;
                }
            }
        }
    };
}
