/**
 * Main Application Entry Point
 * 
 * This is the refactored main application file that uses modular Vue.js components
 * and services following best practices.
 * 
 * @module app
 */

import BudgetBreadcrumb from './components/BudgetBreadcrumb.js';
import BudgetItem from './components/BudgetItem.js';
import BudgetEditModal from './components/BudgetEditModal.js';
import CategoryPicker from './components/CategoryPicker.js';
import { createApiService } from './services/apiService.js';
import { createBudgetStore } from './stores/budgetStore.js';
import { createTransactionModule } from './modules/transactionModule.js';
import { createBudgetModule } from './modules/budgetModule.js';
import { createInsightsModule } from './modules/insightsModule.js';
import { createUserModule } from './modules/userModule.js';
import { createProfileModule } from './modules/profileModule.js';
import { createTrendsModule } from './modules/trendsModule.js';
import { createRulesModule } from './modules/rulesModule.js';
import { createSyncModule } from './modules/syncModule.js';
import { createAccountsModule } from './modules/accountsModule.js';
import { createCategoriesModule } from './modules/categoriesModule.js';
import { createSetupModule } from './modules/setupModule.js';

/** Tabs reserved to administrators */
const ADMIN_TABS = ['rules', 'categories', 'accounts', 'users'];
import * as formatters from './utils/formatters.js';

const { createApp } = Vue;

// Initialize services (will be passed to modules)
let apiService = null;
let budgetStore = null;

/**
 * Main Vue Application
 * 
 * Manages the overall application state and coordinates between
 * different views (transactions, budgets, insights).
 */
createApp({
    // Register imported components
    components: {
        BudgetBreadcrumb,
        BudgetItem,
        BudgetEditModal,
        CategoryPicker
    },

    // Apply mixins from modules
    mixins: [
        createTransactionModule(() => apiService),
        createBudgetModule(() => budgetStore, () => apiService),
        createInsightsModule(() => apiService),
        createUserModule(() => apiService),
        createProfileModule(() => apiService),
        createTrendsModule(() => apiService),
        createRulesModule(() => apiService),
        createSyncModule(() => apiService),
        createAccountsModule(() => apiService),
        createCategoriesModule(() => apiService),
        createSetupModule()
    ],

    data() {
        return {
            // Core state
            activeTab: 'transactions',
            selectedMonth: new Date().getMonth() + 1,
            selectedYear: new Date().getFullYear(),
            locale: localStorage.getItem('locale') || defaultLocale(),
            apiBaseUrl: null,
            
            // Authentication state
            isAuthenticated: false,
            authToken: null,
            username: localStorage.getItem('username'),
            loginForm: {
                apiUrl: localStorage.getItem('apiUrl') || window.location.origin,
                username: '',
                password: ''
            },
            loggingIn: false,
            loginError: null,
            
            // Shared state
            error: null,
            toastMessage: null,
            
            // Debug info
            debugInfo: {
                url: null,
                status: null,
                ok: null,
                bodyPreview: null
            }
        };
    },

    computed: {
        /**
         * Generates array of month options with localized labels
         * @returns {Array<{value: number, label: string}>}
         */
        months() {
            return [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12].map(value => ({
                value,
                label: t(`months.${value}`)
            }));
        },

        /**
         * Generates array of year options (current year - 5 years)
         * @returns {Array<number>}
         */
        years() {
            return formatters.generateYears(6);
        },

        /**
         * Checks if debug mode is enabled via query parameter
         * @returns {boolean}
         */
        isDebugMode() {
            return formatters.isDebugMode();
        },

        /**
         * Safe access to current budget list (prevents undefined errors)
         * @returns {Array}
         */
        safeCurrentBudgetList() {
            return this.currentBudgetList || [];
        },

        /**
         * Safe access to breadcrumb stack (prevents undefined errors)
         * @returns {Array}
         */
        safeBudgetStack() {
            return this.breadcrumbStack || [];
        }
    },

    methods: {
        // === Localization Methods ===
        
        /**
         * Translates a key using the global i18n function
         * @param {string} key - Translation key
         * @param {Object} params - Translation parameters
         * @returns {string}
         */
        t(key, params = {}) {
            return t(key, params);
        },

        /**
         * Changes the application locale
         * @param {string} newLocale - New locale code (e.g., 'fr', 'en')
         */
        changeLocale(newLocale) {
            setLocale(newLocale);
            this.locale = newLocale;
        },

        // === Tab Navigation Methods ===

        /**
         * Back to the home page: transactions of the current month
         */
        goHome() {
            const now = new Date();
            this.selectedMonth = now.getMonth() + 1;
            this.selectedYear = now.getFullYear();
            this.setActiveTab('transactions');
        },
        
        /**
         * Sets the active tab and loads corresponding data
         * @param {string} tab - Tab name ('transactions', 'budget', 'insights', 'users')
         */
        setActiveTab(tab) {
            // Rules, categories, accounts and users are managed by administrators
            if (ADMIN_TABS.includes(tab) && !this.isAdmin) {
                tab = 'transactions';
            }
            this.abortAllExcept(tab);
            this.activeTab = tab;
            
            if (this.isDebugMode) {
                console.log('Switching to tab:', tab);
            }
            
            this.refreshCurrentTab();
        },

        /**
         * Refreshes data for the currently active tab
         */
        refreshCurrentTab() {
            if (!this.apiBaseUrl) {
                this.error = 'apiBaseUrl not configured on #app (data-api-base)';
                if (this.isDebugMode) {
                    console.error('apiBaseUrl missing, cannot refresh current tab');
                }
                return;
            }

            switch (this.activeTab) {
                case 'transactions':
                    this.loadTransactions(this.selectedMonth, this.selectedYear);
                    break;
                case 'budget':
                    this.loadBudget(this.selectedMonth, this.selectedYear);
                    break;
                case 'insights':
                    this.loadInsights(this.selectedMonth, this.selectedYear);
                    break;
                case 'trends':
                    this.loadTrends();
                    break;
                case 'rules':
                    this.loadRules();
                    break;
                case 'categories':
                    this.loadCategories();
                    break;
                case 'accounts':
                    this.loadBankAccounts();
                    this.loadBankBackends();
                    break;
                case 'users':
                    this.loadUsers();
                    break;
                case 'profile':
                    // Profile form is filled by openProfile()
                    break;
            }
        },

        /**
         * Aborts all pending requests except for specified tab
         * @param {string} tab - Tab to keep active
         */
        abortAllExcept(tab) {
            // Abort budget store requests if not on budget tab
            if (tab !== 'budget') {
                this.abortBudget();
            }

            // Abort transaction requests if not on transactions tab
            if (tab !== 'transactions') {
                this.abortTransactions();
            }

            // Abort insights requests if not on insights tab
            if (tab !== 'insights') {
                this.abortInsights();
            }
        },

        // === Formatting Methods (delegated to utils) ===
        
        formatAmount(amount) {
            return formatters.formatAmount(amount, this.locale);
        },

        formatDate(dateString) {
            return formatters.formatDate(dateString, this.locale);
        },

        getTransactionIcon(type) {
            return formatters.getTransactionIcon(type);
        },

        getTransactionType(type) {
            return this.t(`transactionTypes.${type}`);
        },

        getInsightName(name) {
            const translationKey = `insightNames.${name}`;
            const translated = this.t(translationKey);
            return translated !== translationKey ? translated : name;
        },

        getInsightIcon(name) {
            return formatters.getInsightIcon(name);
        },

        // === Budget Navigation Aliases (for template compatibility) ===
        
        /**
         * Navigates to budget item (alias for handleBudgetClick)
         * @param {Object} item - Budget item
         */
        navigateToBudget(item) {
            this.handleBudgetClick(item, this.selectedMonth, this.selectedYear);
        },

        /**
         * Navigates to breadcrumb (alias for handleBreadcrumbClick)
         * @param {number} index - Breadcrumb index
         */
        goToBreadcrumb(index) {
            this.handleBreadcrumbClick(index, this.selectedMonth, this.selectedYear);
        },

        /**
         * Opens budget modal (alias for handleBudgetEdit)
         * @param {Object} item - Budget item
         */
        openBudgetModal(item) {
            this.handleBudgetEdit(item);
        },

        // === Authentication Methods ===
        
        /**
         * Logs in the user and retrieves JWT token
         * @returns {Promise<void>}
         */
        async login() {
            if (!this.loginForm.username || !this.loginForm.password) {
                this.loginError = this.t('loginRequired') || 'Nom d\'utilisateur et mot de passe requis';
                return;
            }

            this.loggingIn = true;
            this.loginError = null;

            try {
                const url = `${this.loginForm.apiUrl}/api/user/login`;
                const response = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        username: this.loginForm.username,
                        password: this.loginForm.password
                    })
                });

                if (!response.ok) {
                    throw new Error('Identifiants invalides');
                }

                const data = await response.json();
                
                if (!data || !data.token) {
                    throw new Error('Token non reçu');
                }

                // Store authentication data
                this.authToken = data.token;
                this.isAuthenticated = true;
                this.apiBaseUrl = this.loginForm.apiUrl + '/api';
                
                // Save to localStorage
                localStorage.setItem('authToken', data.token);
                localStorage.setItem('apiUrl', this.loginForm.apiUrl);
                localStorage.setItem('username', this.loginForm.username);
                this.username = this.loginForm.username;
                
                // Reinitialize services with new API URL and token
                apiService = createApiService(this.apiBaseUrl, this.authToken, () => this.sessionExpired());
                budgetStore = createBudgetStore(apiService);
                
                // Clear password
                this.loginForm.password = '';

                // Load the user profile (name in header, language, admin rights)
                await this.loadCurrentUser();
                
                // Load initial data
                this.refreshCurrentTab();
                
            } catch (error) {
                console.error('Login error:', error);
                this.loginError = error.message || (this.t('loginError') || 'Erreur de connexion');
            } finally {
                this.loggingIn = false;
            }
        },

        /**
         * Expired or invalid token: back to the login screen with a message
         */
        sessionExpired() {
            if (!this.isAuthenticated) {
                return;
            }
            this.logout();
            this.loginError = this.t('sessionExpired');
        },

        /**
         * Logs out the user
         */
        logout() {
            this.closeUserMenu();
            this.isAuthenticated = false;
            this.authToken = null;
            this.currentUser = null;
            this.activeTab = 'transactions';
            this.loginForm.password = '';
            localStorage.removeItem('authToken');
            localStorage.removeItem('username');
            this.username = null;
            
            // Reinitialize services without token
            if (this.apiBaseUrl) {
                apiService = createApiService(this.apiBaseUrl, null);
                budgetStore = createBudgetStore(apiService);
            }
        },

        /**
         * Checks if user is already authenticated from localStorage
         */
        checkAuthentication() {
            const token = localStorage.getItem('authToken');
            const apiUrl = localStorage.getItem('apiUrl');
            
            if (token && apiUrl) {
                this.authToken = token;
                this.isAuthenticated = true;
                this.apiBaseUrl = apiUrl + '/api';
                this.loginForm.apiUrl = apiUrl;
            }
        },

        // === UI Helper Methods ===
        
        /**
         * Shows a temporary toast message
         * @param {string} message - Message to display
         * @param {number} duration - Duration in milliseconds
         */
        showToast(message, duration = 2500) {
            this.toastMessage = message;
            setTimeout(() => {
                this.toastMessage = null;
            }, duration);
        }
    },

    /**
     * Lifecycle hook: Called after component is mounted
     * Initializes services, stores, and loads initial data
     */
    mounted() {
        // Close the user menu when clicking anywhere else
        document.addEventListener('click', () => this.closeUserMenu());

        // Check if already authenticated
        this.checkAuthentication();

        // Not installed yet: the installation wizard replaces the login screen
        if (!this.isAuthenticated) {
            this.checkSetup();
        }

        // Read API base URL from DOM attribute (legacy support)
        try {
            const el = document.getElementById('app');
            if (el && el.dataset && el.dataset.apiBase && !this.apiBaseUrl) {
                this.apiBaseUrl = el.dataset.apiBase;
            }
        } catch (e) {
            console.warn('Unable to read apiBaseUrl from DOM, using default', e);
        }

        if (this.isDebugMode) {
            console.log('apiBaseUrl =', this.apiBaseUrl);
            console.log('isAuthenticated =', this.isAuthenticated);
        }

        // Only initialize services if authenticated
        if (this.isAuthenticated && this.apiBaseUrl) {
            // Initialize API service with token
            apiService = createApiService(this.apiBaseUrl, this.authToken, () => this.sessionExpired());

            // Initialize budget store
            budgetStore = createBudgetStore(apiService);

            // Load the user profile (name in header, language, admin rights)
            this.loadCurrentUser();

            // Load initial data based on active tab
            if (this.activeTab === 'transactions') {
                this.loadTransactions(this.selectedMonth, this.selectedYear);
            } else {
                this.refreshCurrentTab();
            }
        }
    }
}).mount('#app');
