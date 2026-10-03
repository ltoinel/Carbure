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
                // 'idle', 'running', 'success', 'error' or 'busy' (another sync is running)
                syncStatus: 'idle',
                syncLog: [],
                syncStartedAt: null,
                syncDuration: null
            };
        },

        computed: {
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
            async startSync() {
                if (this.syncStatus === 'running') {
                    return;
                }

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
                }
            }
        }
    };
}
