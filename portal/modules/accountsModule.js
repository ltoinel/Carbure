/**
 * Accounts Module
 *
 * Bank accounts followed by the user: list, add (from the accounts woob finds,
 * or manually), modify and delete. Bank credentials never go through the
 * portal: they are configured in woob (php tools/install.php --add-bank).
 *
 * @module accountsModule
 */

/**
 * Creates an accounts module mixin for Vue components
 * @param {Function} getApiService - Function that returns the API service instance
 * @returns {Object} Vue mixin with accounts data and methods
 */
export function createAccountsModule(getApiService) {
    return {
        data() {
            return {
                // Account being modified: {id, account_number, bank_name}
                accountEdit: null,
                newAccount: { account_number: '', bank_name: '' },
                discoveredAccounts: [],
                // Banks configured in woob: [{name, module}]
                bankBackends: [],
                // Banks supported by woob: [{module, description}]
                bankModules: [],
                // Bank of the manual form: a backend name, or '__other' to type it
                newAccountBank: '',
                discovering: false,
                discoverError: null
            };
        },

        methods: {
            /**
             * Loads the banks configured in woob (for the bank selector)
             * @returns {Promise<void>}
             */
            async loadBankBackends() {
                const api = getApiService();
                // The selector still offers "Other" (typed name) if woob does not answer
                const [backends, modules] = await Promise.allSettled([api.fetchBankBackends(), api.fetchBankModules()]);
                this.bankBackends = backends.status === 'fulfilled' ? backends.value : [];
                this.bankModules = modules.status === 'fulfilled' ? modules.value : [];
            },

            /**
             * Supported banks not configured in woob yet
             * @returns {Array}
             */
            otherBankModules() {
                const configured = new Set(this.bankBackends.map(b => b.name));
                return this.bankModules.filter(m => !configured.has(m.module));
            },

            /**
             * Checks if the bank chosen in the form still has to be configured in woob
             * @returns {boolean}
             */
            chosenBankNotConfigured() {
                const bank = this.newAccountBank;
                return bank !== '' && bank !== '__other' && !this.bankBackends.some(b => b.name === bank);
            },

            /**
             * Checks if an account found by woob is followed (from the current list)
             * @param {Object} discovered - Account found by woob
             * @returns {boolean}
             */
            isFollowed(discovered) {
                return this.bankAccounts.some(a => a.bankId === discovered.bankId);
            },

            /**
             * Short display of an account: "BNP ···5678"
             * @param {Object} account - Account
             * @returns {string}
             */
            accountName(account) {
                return `${String(account.bank_name).toUpperCase()} ···${String(account.account_number).slice(-4)}`;
            },

            /**
             * Asks woob for the accounts of the configured banks
             * @returns {Promise<void>}
             */
            async discoverAccounts() {
                this.discovering = true;
                this.discoverError = null;
                try {
                    this.discoveredAccounts = await getApiService().discoverBankAccounts();
                } catch (error) {
                    this.discoverError = error.message;
                } finally {
                    this.discovering = false;
                }
            },

            /**
             * Follows an account
             * @param {string} accountNumber - Account identifier in woob
             * @param {string} bankName - woob backend name
             * @returns {Promise<boolean>} True if added
             */
            async followAccount(accountNumber, bankName) {
                try {
                    await getApiService().createBankAccount(String(accountNumber).trim(), String(bankName).trim());
                    this.showToast(this.t('accountAdded'));
                    await this.loadBankAccounts();
                    return true;
                } catch (error) {
                    this.showToast(error.message);
                    return false;
                }
            },

            /**
             * Adds the account of the manual form
             * @returns {Promise<void>}
             */
            async addAccount() {
                if (this.newAccountBank !== '__other') {
                    this.newAccount.bank_name = this.newAccountBank;
                }
                if (!this.newAccount.account_number.trim() || !this.newAccount.bank_name.trim()) {
                    this.showToast(this.t('accountRequired'));
                    return;
                }
                if (await this.followAccount(this.newAccount.account_number, this.newAccount.bank_name)) {
                    this.newAccount = { account_number: '', bank_name: '' };
                    this.newAccountBank = '';
                }
            },

            /**
             * Starts modifying an account
             * @param {Object} account - Account
             */
            editAccount(account) {
                this.accountEdit = { id: account.id, account_number: account.account_number, bank_name: account.bank_name };
            },

            /**
             * Saves the modified account
             * @returns {Promise<void>}
             */
            async saveAccount() {
                const edit = this.accountEdit;
                try {
                    await getApiService().updateBankAccount(edit.id, String(edit.account_number).trim(), String(edit.bank_name).trim());
                    this.accountEdit = null;
                    this.showToast(this.t('accountUpdated'));
                    await this.loadBankAccounts();
                } catch (error) {
                    this.showToast(error.message);
                }
            },

            /**
             * Stops following an account after confirmation (transactions are kept)
             * @param {Object} account - Account
             * @returns {Promise<void>}
             */
            async removeAccount(account) {
                if (!confirm(this.t('confirmDeleteAccount', { account: this.accountName(account) }))) {
                    return;
                }
                try {
                    await getApiService().deleteBankAccount(account.id);
                    this.showToast(this.t('accountDeleted'));
                    await this.loadBankAccounts();
                } catch (error) {
                    this.showToast(error.message);
                }
            }
        }
    };
}
