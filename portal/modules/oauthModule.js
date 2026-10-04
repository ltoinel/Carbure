/**
 * OAuth Module
 *
 * Consent page of the OAuth authorization of the MCP server. An AI agent that only
 * takes the URL of the server (Claude web, Desktop, mobile) sends the browser to
 * /api/oauth/authorize, which sends it here with the request (?oauth_request=...).
 * Once logged in, the user allows or refuses the agent, and the browser goes back
 * to the agent with an authorization code (or the refusal).
 *
 * @module oauthModule
 */

/** Key of the request kept while the user logs in */
const STORAGE_KEY = 'oauthRequest';

/**
 * Creates the OAuth consent mixin
 * @param {Function} getApiService - Function that returns the API service instance
 * @returns {Object} Vue mixin
 */
export function createOAuthModule(getApiService) {
    return {
        data() {
            return {
                // Authorization request (query string) waiting for the answer of the user
                oauthRequest: null,
                // Agent asking for the authorization: {name, redirect_host}
                oauthClient: null,
                oauthBusy: false
            };
        },

        watch: {
            /**
             * The user is known (logged in, or already logged in): shows the consent
             * @param {Object|null} user - Current user
             */
            currentUser(user) {
                if (user) {
                    this.showOAuthConsent();
                }
            }
        },

        created() {
            this.captureOAuthRequest();
        },

        methods: {
            /**
             * Takes the authorization request out of the URL, and keeps it while the
             * user logs in
             */
            captureOAuthRequest() {
                const params = new URLSearchParams(window.location.search);
                const request = params.get('oauth_request');
                if (!request) {
                    try {
                        this.oauthRequest = sessionStorage.getItem(STORAGE_KEY);
                    } catch (e) {
                        this.oauthRequest = null;
                    }
                    return;
                }
                this.oauthRequest = request;
                try {
                    sessionStorage.setItem(STORAGE_KEY, request);
                } catch (e) {
                    // Kept in memory only
                }
                params.delete('oauth_request');
                const query = params.toString();
                window.history.replaceState(null, '', window.location.pathname + (query ? `?${query}` : '') + window.location.hash);
            },

            /**
             * Shows the consent of the pending request, with the name of the agent
             * @returns {Promise<void>}
             */
            async showOAuthConsent() {
                if (!this.oauthRequest || this.oauthClient || !getApiService()) {
                    return;
                }
                try {
                    this.oauthClient = await getApiService().fetchOAuthClient(this.oauthRequest);
                } catch (error) {
                    this.clearOAuthRequest();
                    this.showToast(`${this.t('oauthInvalid')} : ${error.message}`, 6000);
                }
            },

            /**
             * Answer of the user: the browser goes back to the agent
             * @param {boolean} approved - True if the user allows the agent
             * @returns {Promise<void>}
             */
            async answerOAuth(approved) {
                this.oauthBusy = true;
                try {
                    const { redirect } = await getApiService().approveOAuth(this.oauthRequest, approved);
                    this.clearOAuthRequest();
                    window.location.assign(redirect);
                } catch (error) {
                    this.showToast(error.message);
                    this.oauthBusy = false;
                }
            },

            /**
             * Forgets the pending request
             */
            clearOAuthRequest() {
                this.oauthRequest = null;
                this.oauthClient = null;
                try {
                    sessionStorage.removeItem(STORAGE_KEY);
                } catch (e) {
                    // Nothing kept
                }
            }
        }
    };
}
