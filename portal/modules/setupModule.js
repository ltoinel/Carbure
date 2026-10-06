/**
 * Setup Module
 *
 * Installation wizard, shown instead of the login screen as long as Carbure is
 * not installed (the API then answers GET /api/setup): database, schema
 * (created or updated), first administrator.
 *
 * @module setupModule
 */

/**
 * Creates the setup module mixin for Vue components
 * @returns {Object} Vue mixin with the wizard data and methods
 */
export function createSetupModule() {
    return {
        data() {
            return {
                // true while Carbure is not installed
                setupMode: false,
                // database, review or done
                setupStep: 'database',
                setupCodeRequired: false,
                setupPasswordFromEnvironment: false,
                setupForm: {
                    code: '',
                    db_host: '',
                    db_port: 3306,
                    db_name: '',
                    db_user: '',
                    db_password: '',
                    admin_user: 'admin',
                    admin_password: '',
                    admin_password_confirm: '',
                    admin_email: '',
                    language: 'fr',
                    backup_confirmed: false,
                    // New database: categories and rules, and insights, to start with
                    starter_categories: true,
                    starter_insights: true
                },
                // Answer of /setup/database: {state, version, pending, hasAdmin}
                setupDatabase: null,
                setupError: null,
                setupBusy: false
            };
        },

        computed: {
            /**
             * The first administrator has to be created
             * @returns {boolean}
             */
            setupNeedsAdmin() {
                return !!this.setupDatabase && !this.setupDatabase.hasAdmin;
            }
        },

        methods: {
            /**
             * URL of the setup API, on the server of the portal
             * @param {string} path - '' or '/database', '/install'
             * @returns {string}
             */
            setupUrl(path = '') {
                return `${(this.loginForm.apiUrl || window.location.origin).replace(/\/$/, '')}/api/setup${path}`;
            },

            /**
             * Shows the wizard if Carbure is not installed yet
             * @returns {Promise<void>}
             */
            async checkSetup() {
                try {
                    const response = await fetch(this.setupUrl());
                    if (!response.ok) {
                        return;
                    }
                    const data = await response.json();
                    if (!data || data.setup !== true) {
                        return;
                    }
                    const d = data.defaults || {};
                    Object.assign(this.setupForm, {
                        db_host: d.db_host || 'localhost',
                        db_port: d.db_port || 3306,
                        db_name: d.db_name || 'carbure',
                        db_user: d.db_user || 'carbure',
                        admin_user: d.admin_user || 'admin',
                        language: d.language || this.locale
                    });
                    this.setupCodeRequired = !!data.codeRequired;
                    this.setupPasswordFromEnvironment = !!data.dbPasswordFromEnvironment;
                    this.setupMode = true;
                } catch (error) {
                    // Installed (or unreachable): the login screen is shown
                }
            },

            /**
             * Sends a request of the wizard
             * @param {string} path - '/database' or '/install'
             * @returns {Promise<Object>} The answer
             * @throws {Error} With the message of the server
             */
            async setupRequest(path) {
                const response = await fetch(this.setupUrl(path), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(this.setupForm)
                });
                const data = await response.json().catch(() => ({}));
                if (!response.ok) {
                    throw new Error(data.error || `HTTP ${response.status}`);
                }
                return data;
            },

            /**
             * Step 1: connects to the database and tells what will be done
             * @returns {Promise<void>}
             */
            async setupCheckDatabase() {
                this.setupBusy = true;
                this.setupError = null;
                try {
                    this.setupDatabase = await this.setupRequest('/database');
                    this.setupStep = 'review';
                    this.$nextTick(() => document.querySelector('.setup-card h2')?.focus());
                } catch (error) {
                    this.setupError = error.message;
                } finally {
                    this.setupBusy = false;
                }
            },

            /**
             * Step 2: installs or updates Carbure
             * @returns {Promise<void>}
             */
            async setupInstall() {
                this.setupError = null;
                if (this.setupNeedsAdmin) {
                    if (this.setupForm.admin_password.length < 8) {
                        this.setupError = this.t('setupPasswordTooShort');
                        return;
                    }
                    if (this.setupForm.admin_password !== this.setupForm.admin_password_confirm) {
                        this.setupError = this.t('setupPasswordMismatch');
                        return;
                    }
                }
                if (this.setupDatabase.state === 'outdated' && !this.setupForm.backup_confirmed) {
                    this.setupError = this.t('setupBackupRequired');
                    return;
                }
                this.setupBusy = true;
                try {
                    const result = await this.setupRequest('/install');
                    this.setupDatabase = { ...this.setupDatabase, version: result.version };
                    if (result.username) {
                        this.loginForm.username = result.username;
                    }
                    this.setupStep = 'done';
                    this.$nextTick(() => document.querySelector('.setup-card h2')?.focus());
                } catch (error) {
                    this.setupError = error.message;
                } finally {
                    this.setupBusy = false;
                }
            },

            /**
             * Leaves the wizard for the login screen
             */
            finishSetup() {
                this.setupMode = false;
                this.setupForm.admin_password = '';
                this.setupForm.admin_password_confirm = '';
                this.setupForm.db_password = '';
                this.$nextTick(() => document.getElementById(this.loginForm.username ? 'loginPassword' : 'loginUsername')?.focus());
            }
        }
    };
}
