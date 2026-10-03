/**
 * Sync Module
 *
 * Starts a bank synchronization from the portal and shows its progress live
 * (Server-Sent Events read through fetch, which can send the JWT).
 *
 * @module syncModule
 */

/**
 * Creates a sync module mixin for Vue components
 * @param {Function} getApiService - Function that returns the API service instance
 * @returns {Object} Vue mixin with sync data and methods
 */
export function createSyncModule(getApiService) {
    return {
        data() {
            return {
                bankAccounts: [],
                // Account being synchronized alone (bankId), null for all
                syncTarget: null,
                // 'idle', 'running', 'success', 'error' or 'busy' (another sync is running)
                syncStatus: 'idle',
                syncLog: [],
                syncStartedAt: null,
                syncDuration: null,
                showSyncLog: false
            };
        },

        computed: {
            /**
             * Steps of the synchronization built from the progress messages:
             * per account (coming, history, notification), then categorization.
             * Each step: {kind, status: running|ok|error, count, error}
             * @returns {{accounts: Array<{id: string, steps: Array}>, global: Array}}
             */
            syncSteps() {
                const accounts = [];
                const global = [];
                let current = null;

                const close = status => {
                    if (current && current.status === 'running') {
                        current.status = status;
                    }
                };
                const account = id => {
                    let found = accounts.find(a => a.id === id);
                    if (!found) {
                        found = { id, steps: [] };
                        accounts.push(found);
                    }
                    return found;
                };

                for (const line of this.syncLog) {
                    let match;
                    if ((match = line.match(/^Syncing (.+) \((coming|history)\)\.\.\.$/))) {
                        close('ok');
                        current = { kind: match[2], status: 'running', count: 0, created: null, error: null };
                        account(match[1]).steps.push(current);
                    } else if ((match = line.match(/^Done (.+) \((coming|history)\): (\d+) received, (\d+) new$/))) {
                        // Result of the step sent by the server
                        if (current && current.kind === match[2]) {
                            current.count = Number(match[3]);
                            current.created = Number(match[4]);
                            current.status = 'ok';
                        }
                    } else if ((match = line.match(/^Error syncing (.+?): (.*)$/))) {
                        if (current && current.status === 'running') {
                            current.status = 'error';
                            current.error = match[2];
                        } else {
                            account(match[1]).steps.push({ kind: 'sync', status: 'error', count: 0, error: match[2] });
                        }
                    } else if ((match = line.match(/^Notifying users of (.+)\.\.\.$/))) {
                        close('ok');
                        current = { kind: 'notify', status: 'running', count: 0, error: null };
                        account(match[1]).steps.push(current);
                    } else if (line.startsWith('Updating missing categories')) {
                        close('ok');
                        current = { kind: 'categories', status: 'running', count: 0, error: null };
                        global.push(current);
                    } else if (line.startsWith('Synchronization complete')) {
                        close('ok');
                    } else if (line.startsWith('Error')) {
                        global.push({ kind: 'error', status: 'error', count: 0, error: line });
                    } else if (current && current.status === 'running' && current.created === null && !line.includes('already in progress')) {
                        // Any other message is a transaction being saved (live count)
                        current.count++;
                    }
                }

                // The stream ended while a step was still running
                if (this.syncStatus !== 'running') {
                    close('error');
                }

                return { accounts, global };
            },

            /**
             * Errors reported during the last synchronization
             * @returns {Array<string>}
             */
            syncErrors() {
                return this.syncLog.filter(line => line.startsWith('Error'));
            }
        },

        methods: {
            /**
             * Search of the woob issues about a bank module (known problems and fixes)
             * @param {string} bankId - Account "<number>@<backend>" (backend named after the module)
             * @returns {string} URL of the search on the woob GitLab
             */
            woobIssuesUrl(bankId) {
                const module = String(bankId).split('@').pop();
                return 'https://gitlab.com/search?group_id=11540390&project_id=25520182&scope=work_items'
                    + `&search=${encodeURIComponent(module)}&sort=created_desc`;
            },

            /**
             * Loads the bank accounts of the user
             * @returns {Promise<void>}
             */
            async loadBankAccounts() {
                const apiService = getApiService();
                if (!apiService) {
                    return;
                }
                try {
                    this.bankAccounts = await apiService.fetchBankAccounts();
                } catch (error) {
                    this.error = error.message;
                }
            },

            /**
             * Starts a synchronization and follows its progress
             * @returns {Promise<void>}
             */
            async startSync(account = null) {
                if (this.syncStatus === 'running') {
                    return;
                }

                this.syncTarget = typeof account === 'string' ? account : null;
                this.syncStatus = 'running';
                this.syncLog = [];
                this.syncStartedAt = Date.now();
                this.syncDuration = null;

                try {
                    await getApiService().syncBanks(message => {
                        this.syncLog.push(message);
                        this.$nextTick(() => {
                            const log = document.querySelector('.sync-log');
                            if (log) {
                                log.scrollTop = log.scrollHeight;
                            }
                        });
                    });

                    const last = this.syncLog[this.syncLog.length - 1] || '';
                    if (last.includes('already in progress')) {
                        this.syncStatus = 'busy';
                    } else if (last.includes('complete')) {
                        this.syncStatus = this.syncErrors.length ? 'error' : 'success';
                    } else {
                        this.syncStatus = 'error';
                    }
                } catch (error) {
                    this.syncLog.push(`Error: ${error.message}`);
                    this.syncStatus = 'error';
                } finally {
                    this.syncDuration = Math.round((Date.now() - this.syncStartedAt) / 1000);
                    // Date and result of the last synchronization of each account
                    if (this.isAdmin) {
                        this.loadBankAccounts();
                    }
                }
            }
        }
    };
}
