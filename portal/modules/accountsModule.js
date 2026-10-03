/**
 * Accounts Module
 *
 * Bank accounts of the household (administrators): list with the result of the
 * last synchronization, add (from the accounts woob finds, or manually) and
 * modify in a modal, delete. A bank not configured in woob yet is connected
 * from the modal: its credentials are given to woob, never stored by Carbure.
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
                showAccountModal: false,
                savingAccount: false,
                // Account of the modal: {id (null for a new one), account_number, bank_name}
                accountForm: { id: null, account_number: '', bank_name: '' },
                discoveredAccounts: [],
                // Banks configured in woob: [{name, module}]
                bankBackends: [],
                // Banks supported by woob: [{module, description}]
                bankModules: [],
                // Bank of the manual form: a backend name, or '__other' to type it
                newAccountBank: '',
                discovering: false,
                discoverError: null,
                // Configuration in woob of a bank not configured yet
                bankSetup: { module: '', backend: '', description: '', fields: [], values: {}, loading: false, busy: false, error: null }
            };
        },

        watch: {
            /**
             * A supported bank not configured yet: load the settings it asks for
             */
            newAccountBank() {
                if (this.showAccountModal && this.chosenBankNotConfigured()) {
                    this.loadBankSetup(this.newAccountBank);
                } else {
                    this.bankSetup = { ...this.bankSetup, module: '', fields: [], error: null };
                }
            }
        },

        methods: {
            /**
             * Loads the settings asked by a woob module (login, password...)
             * @param {string} module - woob module
             * @returns {Promise<void>}
             */
            async loadBankSetup(module) {
                this.bankSetup = { module, backend: module, description: '', fields: [], values: {}, loading: true, busy: false, error: null };
                try {
                    const info = await getApiService().fetchBankModuleFields(module);
                    if (this.bankSetup.module !== module) {
                        return;
                    }
                    const values = {};
                    info.fields.forEach(f => { values[f.key] = f.default || (f.choices.length ? f.choices[0].value : ''); });
                    this.bankSetup = { ...this.bankSetup, description: info.description, fields: info.fields, values, loading: false };
                } catch (error) {
                    this.bankSetup = { ...this.bankSetup, loading: false, error: error.message };
                }
            },

            /**
             * Configures the bank in woob, then shows its accounts to follow
             * @returns {Promise<void>}
             */
            async connectBank() {
                const setup = this.bankSetup;
                const missing = setup.fields.find(f => f.required && !String(setup.values[f.key] || '').trim());
                if (missing) {
                    this.bankSetup = { ...setup, error: this.t('bankSettingRequired', { field: missing.label }) };
                    return;
                }
                // Format expected by the bank module (e.g. a 6-digit code)
                const invalid = setup.fields.find(f => {
                    const value = String(setup.values[f.key] || '');
                    try {
                        return f.regexp && value !== '' && !new RegExp(f.regexp).test(value);
                    } catch (e) {
                        return false;
                    }
                });
                if (invalid) {
                    this.bankSetup = { ...setup, error: this.t('bankSettingFormat', { field: invalid.label }) };
                    return;
                }
                this.bankSetup = { ...setup, busy: true, error: null };
                try {
                    const result = await getApiService().createBankBackend(setup.module, setup.backend, setup.values);
                    // The credentials leave the page as soon as woob has them
                    this.bankSetup = { module: '', backend: '', description: '', fields: [], values: {}, loading: false, busy: false, error: null };
                    await this.loadBankBackends();
                    // Configured now, even if woob lists it a bit later
                    if (!this.bankBackends.some(b => b.name === result.backend)) {
                        this.bankBackends = [...this.bankBackends, { name: result.backend, module: setup.module }];
                    }
                    this.newAccountBank = result.backend;
                    this.discoveredAccounts = result.accounts;
                    this.showToast(this.t(result.accounts.length ? 'bankConnected' : 'bankConnectedNoAccount'));
                } catch (error) {
                    this.bankSetup = { ...this.bankSetup, busy: false, error: error.message };
                }
            },

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
             * Opens the account modal to add an account, or to modify the given one
             * @param {Object|null} account - Account to modify
             */
            openAccountModal(account = null) {
                this.accountForm = account
                    ? { id: account.id, account_number: account.account_number, bank_name: account.bank_name }
                    : { id: null, account_number: '', bank_name: '' };
                const bank = account ? account.bank_name : '';
                const known = this.bankBackends.some(b => b.name === bank) || this.bankModules.some(m => m.module === bank);
                this.newAccountBank = !bank ? '' : known ? bank : '__other';
                this.discoveredAccounts = [];
                this.discoverError = null;
                this.showAccountModal = true;
            },

            /**
             * Closes the account modal
             */
            closeAccountModal() {
                this.showAccountModal = false;
                this.bankSetup = { module: '', backend: '', description: '', fields: [], values: {}, loading: false, busy: false, error: null };
            },

            /**
             * Fills the modal with an account found by woob
             * @param {Object} discovered - Account found by woob
             */
            pickDiscovered(discovered) {
                this.accountForm.account_number = String(discovered.account_number);
                const known = this.bankBackends.some(b => b.name === discovered.bank_name);
                this.newAccountBank = known ? discovered.bank_name : '__other';
                this.accountForm.bank_name = discovered.bank_name;
            },

            /**
             * Adds or modifies the account of the modal
             * @returns {Promise<void>}
             */
            async submitAccountForm() {
                const form = this.accountForm;
                if (this.newAccountBank !== '__other') {
                    form.bank_name = this.newAccountBank;
                }
                const number = String(form.account_number).trim();
                const bank = String(form.bank_name).trim();
                if (!number || !bank) {
                    this.showToast(this.t('accountRequired'));
                    return;
                }
                this.savingAccount = true;
                try {
                    if (form.id) {
                        await getApiService().updateBankAccount(form.id, number, bank);
                        this.showToast(this.t('accountUpdated'));
                    } else {
                        await getApiService().createBankAccount(number, bank);
                        this.showToast(this.t('accountAdded'));
                    }
                    this.showAccountModal = false;
                    await this.loadBankAccounts();
                } catch (error) {
                    this.showToast(error.message);
                } finally {
                    this.savingAccount = false;
                }
            },

            /**
             * Date of the last synchronization: "today 07:02", or the short date
             * @param {string} value - "YYYY-MM-DD HH:MM:SS" (server time)
             * @returns {string}
             */
            formatSyncDate(value) {
                const date = new Date(String(value).replace(' ', 'T'));
                if (isNaN(date)) {
                    return value;
                }
                const time = date.toLocaleTimeString(this.locale, { hour: '2-digit', minute: '2-digit' });
                if (date.toDateString() === new Date().toDateString()) {
                    return this.t('todayAt', { time });
                }
                return `${date.toLocaleDateString(this.locale, { day: 'numeric', month: 'short', year: 'numeric' })} ${time}`;
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
