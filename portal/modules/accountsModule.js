/**
 * Accounts Module
 *
 * Bank accounts of the household (administrators): list with the result of the
 * last synchronization, add (from the accounts woob finds, or manually) and
 * modify in a modal, delete. Bank credentials never go through the
 * portal: they are configured in woob (php tools/carbure.php add-bank).
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
                // Account of the modal: {id (null for a new one), account_number, bank_name, user_id}
                accountForm: { id: null, account_number: '', bank_name: '', user_id: null },
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
             * Opens the account modal to add an account, or to modify the given one
             * @param {Object|null} account - Account to modify
             */
            openAccountModal(account = null) {
                const me = Number(this.currentUser?.id) || null;
                this.accountForm = account
                    ? { id: account.id, account_number: account.account_number, bank_name: account.bank_name, user_id: Number(account.user_id) || me }
                    : { id: null, account_number: '', bank_name: '', user_id: me };
                const bank = account ? account.bank_name : '';
                const known = this.bankBackends.some(b => b.name === bank) || this.bankModules.some(m => m.module === bank);
                this.newAccountBank = !bank ? '' : known ? bank : '__other';
                this.discoveredAccounts = [];
                this.discoverError = null;
                this.showAccountModal = true;
                // Owners to choose from
                if (!this.users.length) {
                    this.loadUsers();
                }
            },

            /**
             * Closes the account modal
             */
            closeAccountModal() {
                this.showAccountModal = false;
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
                        await getApiService().updateBankAccount(form.id, number, bank, form.user_id);
                        this.showToast(this.t('accountUpdated'));
                    } else {
                        await getApiService().createBankAccount(number, bank, form.user_id);
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
