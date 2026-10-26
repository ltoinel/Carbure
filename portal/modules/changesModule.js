/**
 * Changes Module
 *
 * Reloads the open tab when the data it shows changed on the server (another member
 * of the household, a synchronization): GET /api/changes gives one version per kind of
 * data, checked when the page comes back to the foreground and every minute while it
 * is visible. Only the tab whose kinds moved is reloaded; the other tabs reload their
 * data when they are opened. The writes of the portal itself answer with their new
 * versions (X-Carbure-Changes header): they reload nothing more.
 *
 * @module changesModule
 */

/** Delay between two checks while the page is visible (ms) */
export const CHANGES_INTERVAL = 60000;

/**
 * Kinds of data shown by each tab: the insights and the trends are computed from the
 * transactions and the budgets
 */
export const TAB_KINDS = {
    transactions: ['transactions', 'categories', 'accounts'],
    budget: ['budgets', 'transactions', 'categories'],
    insights: ['insights', 'transactions', 'budgets', 'categories'],
    trends: ['transactions', 'budgets', 'categories'],
    rules: ['categories'],
    categories: ['categories'],
    accounts: ['accounts'],
    sync: ['accounts'],
    config: ['settings']
};

/**
 * Versions of the X-Carbure-Changes header ("budgets=1835, transactions=1836")
 * @param {string|null} header - Value of the header
 * @returns {Object<string, number>} Version by kind (empty without the header)
 */
export function parseChangesHeader(header) {
    const versions = {};
    for (const part of (header || '').split(',')) {
        const [kind, version] = part.trim().split('=');
        if (kind && /^\d+$/.test(version || '')) {
            versions[kind] = Number(version);
        }
    }
    return versions;
}

/**
 * Kinds whose version moved
 * @param {Object<string, number>} known - Versions of the data loaded
 * @param {Object<string, number>} current - Versions of the server
 * @returns {string[]} The kinds that changed since they were loaded
 */
export function movedKinds(known, current) {
    return Object.keys(current).filter(kind => known[kind] !== current[kind]);
}

/**
 * Versions known after a write of the portal: a kind it bumped by one is up to date
 * (the portal reloaded it after its write); a kind bumped more was also changed by
 * someone else meanwhile, and stays to be reloaded by the next check
 * @param {Object<string, number>} known - Versions of the data loaded
 * @param {Object<string, number>} written - Versions sent by the write
 * @returns {Object<string, number>} The versions known
 */
export function afterOwnWrite(known, written) {
    const versions = { ...known };
    for (const [kind, version] of Object.entries(written)) {
        if (versions[kind] === version - 1) {
            versions[kind] = version;
        }
    }
    return versions;
}

/**
 * Creates the changes module mixin for Vue components
 * @param {Function} getApiService - Function that returns the API service instance
 * @returns {Object} Vue mixin with the data and methods of the change markers
 */
export function createChangesModule(getApiService) {
    // Not reactive: nothing is displayed from them
    let known = null;
    let timer = null;
    let onVisible = null;

    return {
        methods: {
            /**
             * Starts watching the changes of the session: the versions of the data being
             * loaded, then a check when the page comes back and every minute
             * @returns {Promise<void>}
             */
            async startChangeWatch() {
                this.stopChangeWatch();
                onVisible = () => {
                    if (!document.hidden) {
                        this.checkChanges();
                    }
                };
                document.addEventListener('visibilitychange', onVisible);
                timer = setInterval(onVisible, CHANGES_INTERVAL);
                await this.checkChanges();
            },

            /**
             * Stops watching the changes (logout)
             */
            stopChangeWatch() {
                if (onVisible) {
                    document.removeEventListener('visibilitychange', onVisible);
                }
                clearInterval(timer);
                known = null;
                timer = null;
                onVisible = null;
            },

            /**
             * Versions sent by a write of the portal (X-Carbure-Changes)
             * @param {Object<string, number>} written - Version by kind
             */
            ownChanges(written) {
                if (known) {
                    known = afterOwnWrite(known, written);
                }
            },

            /**
             * Reloads the open tab if the data it shows changed. Not while a modal is open
             * (a form being filled): the next check reloads it
             * @returns {Promise<void>}
             */
            async checkChanges() {
                if (!this.isAuthenticated || document.querySelector('.modal-overlay')) {
                    return;
                }
                let changes;
                try {
                    changes = await getApiService().fetchChanges();
                } catch (error) {
                    // Offline, server restarting: the next check
                    return;
                }
                if (changes === null) {
                    // Server without change markers (older version): nothing to watch
                    this.stopChangeWatch();
                    return;
                }
                const current = Object.fromEntries(Object.entries(changes).map(([kind, change]) => [kind, change.version]));
                if (known === null) {
                    known = current;
                    return;
                }
                const moved = movedKinds(known, current);
                known = current;
                if (moved.includes('transactions')) {
                    this.loadPeriods();
                }
                if (moved.some(kind => (TAB_KINDS[this.activeTab] || []).includes(kind))) {
                    this.refreshCurrentTab();
                }
            }
        }
    };
}
