/**
 * Logs Module
 *
 * Logs tab (administrators): the entries of the log files of the instance,
 * newest first, filtered by level, text or request uid.
 *
 * @module logsModule
 */

/**
 * Creates the logs module mixin for Vue components
 * @param {Function} getApiService - Function that returns the API service instance
 * @returns {Object} Vue mixin with the logs data and methods
 */
export function createLogsModule(getApiService) {
    return {
        data() {
            return {
                logFiles: [],
                logFile: '',
                logLevel: 'INFO',
                logSearch: '',
                logEntries: [],
                logTruncated: false,
                loadingLogs: false,
                // Days the log files are kept (0: forever), null until loaded
                logRetention: null
            };
        },

        methods: {
            /**
             * Loads the list of log files, then the entries of the newest one
             * @returns {Promise<void>}
             */
            async loadLogs() {
                // Names of the users of the entries
                if (!this.users.length) {
                    this.loadUsers();
                }
                getApiService().fetchLogRetention()
                    .then(result => { this.logRetention = Number(result.days) || 0; })
                    .catch(() => { this.logRetention = null; });
                try {
                    this.logFiles = await getApiService().fetchLogFiles();
                    if (!this.logFiles.some(f => f.name === this.logFile)) {
                        // Production logs first (the dev/test ones have a prefix)
                        const production = this.logFiles.find(f => /^carbure_\d{8}\.log$/.test(f.name));
                        this.logFile = (production || this.logFiles[0] || {}).name || '';
                    }
                    await this.loadLogEntries();
                } catch (error) {
                    this.showToast(error.message);
                }
            },

            /**
             * Loads the entries of the selected file with the filters
             * @returns {Promise<void>}
             */
            async loadLogEntries() {
                if (!this.logFile) {
                    this.logEntries = [];
                    return;
                }
                this.loadingLogs = true;
                try {
                    const result = await getApiService().fetchLogEntries(this.logFile, this.logLevel, this.logSearch.trim(), 300);
                    this.logEntries = result.entries;
                    this.logTruncated = result.truncated;
                } catch (error) {
                    this.showToast(error.message);
                } finally {
                    this.loadingLogs = false;
                }
            },

            /**
             * Name of the user of an entry (its id if unknown)
             * @param {number} id - User id
             * @returns {string}
             */
            logUserName(id) {
                const user = this.users.find(u => Number(u.id) === Number(id));
                return user ? user.username : `#${id}`;
            },

            /**
             * Shows every entry of a request (same uid)
             * @param {string} uid - Request uid
             * @returns {Promise<void>}
             */
            filterLogUid(uid) {
                this.logSearch = uid;
                this.logLevel = 'DEBUG';
                return this.loadLogEntries();
            },

            /**
             * Size of a file for humans
             * @param {number} bytes - Size
             * @returns {string}
             */
            formatLogSize(bytes) {
                const kb = Number(bytes) / 1024;
                return kb < 1024 ? `${Math.max(1, Math.round(kb))} Ko` : `${(kb / 1024).toFixed(1)} Mo`;
            }
        }
    };
}
