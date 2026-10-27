/**
 * Insights Module
 * 
 * Manages insights-related state and methods.
 * Provides functionality for loading and displaying financial insights.
 * 
 * @module insightsModule
 */

/**
 * Creates an insights module mixin for Vue components
 * @param {Function} getApiService - Function that returns the API service instance
 * @returns {Object} Vue mixin with insights methods
 */
export function createInsightsModule(getApiService) {
    return {
        data() {
            return {
                insights: [],
                loadingInsights: false,
                // Insights hidden, offered by the + button: {id, name, color, icon}
                disabledInsights: [],
                showInsightCatalog: false,
                togglingInsight: null,
                // Insight of the modal: {id, name, color, sql}
                insightForm: null,
                savingInsight: false,
                insightError: null,
                insightColors: ['red', 'orange', 'amber', 'lime', 'green', 'teal', 'cyan', 'blue', 'indigo', 'purple', 'pink', 'brown', 'gray'],
                // Colors of the cards (--insight-color)
                insightColorValues: { red: '#ef4444', orange: '#f59e0b', amber: '#eab308', lime: '#84cc16', green: '#10b981', teal: '#14b8a6', cyan: '#06b6d4', blue: '#3b82f6', indigo: '#6366f1', purple: '#a855f7', pink: '#ec4899', brown: '#a16207', gray: '#6b7280' },
                insightIcons: ['trending_down', 'trending_up', 'savings', 'account_balance_wallet', 'payments', 'credit_card',
                    'shopping_cart', 'restaurant', 'local_gas_station', 'directions_car', 'home', 'bolt', 'local_hospital',
                    'school', 'flight', 'sports_esports', 'pets', 'receipt_long', 'percent', 'analytics', 'pie_chart',
                    'show_chart', 'warning', 'star'],
                // Check of the query while it is typed: idle, checking, valid, invalid
                insightCheck: { state: 'idle', amount: null, error: null },
                insightsController: null
            };
        },

        watch: {
            'insightForm.sql'() {
                this.scheduleInsightCheck();
            }
        },

        methods: {
            /**
             * Loads insights for selected month/year
             * @param {number} month - Month (1-12)
             * @param {number} year - Year
             * @returns {Promise<void>}
             */
            async loadInsights(month, year) {
                const apiService = getApiService();
                if (!apiService) {
                    console.error('API service not initialized');
                    return;
                }

                this.loadingInsights = true;
                this.error = null;

                try {
                    // Cancel previous request
                    if (this.insightsController) {
                        this.insightsController.abort();
                    }
                    this.insightsController = new AbortController();

                    const data = await apiService.fetchInsights(
                        month,
                        year,
                        this.insightsController.signal
                    );

                    this.insights = Array.isArray(data) ? data : [];
                    await this.loadDisabledInsights();

                    // Show debug toast in dev mode
                    if (this.isDebugMode) {
                        this.showToast(`Insights loaded: ${this.insights.length} items`);
                    }
                } catch (err) {
                    if (err.name === 'AbortError') {
                        return;
                    }
                    
                    console.error('Insights loading error:', err);
                    this.error = this.apiErrorText(err);
                    this.insights = [];
                } finally {
                    this.loadingInsights = false;
                    this.insightsController = null;
                }
            },

            /**
             * Loads the insights hidden, offered by the + button (none on a failure:
             * the button then only creates an insight)
             * @returns {Promise<void>}
             */
            async loadDisabledInsights() {
                try {
                    this.disabledInsights = await getApiService().fetchDisabledInsights();
                } catch (error) {
                    this.disabledInsights = [];
                }
            },

            /**
             * Opens the list of the insights to add (the hidden ones; administrators also create one)
             * @returns {void}
             */
            openInsightCatalog() {
                this.showInsightCatalog = true;
                this.$nextTick(() => document.querySelector('.insight-catalog button')?.focus());
            },

            /**
             * Closes the list of the insights to add
             * @returns {void}
             */
            closeInsightCatalog() {
                this.showInsightCatalog = false;
            },

            /**
             * Creates an insight from the list of the insights to add (administrators)
             * @returns {Promise<void>}
             */
            async createInsightFromCatalog() {
                this.showInsightCatalog = false;
                await this.openInsightModal();
            },

            /**
             * Shows a hidden insight, or hides a shown one, for the whole household
             * @param {Object} insight - Insight {id, name}
             * @param {boolean} enabled - True to show it
             * @returns {Promise<void>}
             */
            async setInsightEnabled(insight, enabled) {
                this.togglingInsight = insight.id;
                try {
                    await getApiService().setInsightEnabled(insight.id, enabled);
                    const name = this.getInsightName(insight.name);
                    this.showToast(this.t(enabled ? 'insightShown' : 'insightHidden', { name }));
                    if (enabled && this.disabledInsights.length <= 1) {
                        this.showInsightCatalog = false;
                    }
                    await this.loadInsights(this.selectedMonth, this.selectedYear);
                } catch (error) {
                    this.showToast(this.apiErrorText(error));
                } finally {
                    this.togglingInsight = null;
                }
            },

            /**
             * Opens the insight modal to add an insight, or to modify the given one
             * (its SQL is fetched: the insights of the month do not carry it)
             * @param {Object|null} insight - Insight to modify
             * @returns {Promise<void>}
             */
            async openInsightModal(insight = null) {
                this.insightError = null;
                if (!insight) {
                    this.insightForm = {
                        id: null,
                        name: '',
                        color: 'blue',
                        icon: '',
                        sql: 'SELECT SUM(amount) AS amount FROM bank_transaction WHERE category = 1 AND MONTH(date) = {month} AND YEAR(date) = {year}'
                    };
                } else {
                    try {
                        const definitions = await getApiService().fetchInsightDefinitions();
                        const definition = definitions.find(d => Number(d.id) === Number(insight.id));
                        this.insightForm = { id: Number(insight.id), name: insight.name, color: insight.color, icon: insight.icon || '', sql: definition ? definition.sql : '' };
                    } catch (error) {
                        this.showToast(this.apiErrorText(error));
                        return;
                    }
                }
                this.$nextTick(() => document.getElementById('insight-name')?.focus());
            },

            /**
             * Closes the insight modal
             */
            closeInsightModal() {
                this.insightForm = null;
                clearTimeout(this.insightCheckTimer);
                this.insightCheck = { state: 'idle', amount: null, error: null };
            },

            /**
             * Checks the query of the modal on the server, a moment after the last key
             * @returns {void}
             */
            scheduleInsightCheck() {
                clearTimeout(this.insightCheckTimer);
                if (!this.insightForm || !this.insightForm.sql.trim()) {
                    this.insightCheck = { state: 'idle', amount: null, error: null };
                    return;
                }
                this.insightCheck = { ...this.insightCheck, state: 'checking' };
                this.insightCheckTimer = setTimeout(async () => {
                    const sql = this.insightForm?.sql;
                    try {
                        const result = await getApiService().checkInsight(sql, this.selectedMonth, this.selectedYear);
                        // Ignore the answer to an older version of the query
                        if (!this.insightForm || this.insightForm.sql !== sql) {
                            return;
                        }
                        // The cause of an invalid query: translated by its code, like an API error
                        this.insightCheck = result.valid
                            ? { state: 'valid', amount: result.amount, error: null }
                            : { state: 'invalid', amount: null, error: this.apiErrorText({ message: result.error, code: result.error_code, params: result.params }) };
                    } catch (error) {
                        this.insightCheck = { state: 'invalid', amount: null, error: this.apiErrorText(error) };
                    }
                }, 600);
            },

            /**
             * Saves the insight of the modal (the server tests the query)
             * @returns {Promise<void>}
             */
            async submitInsight() {
                this.savingInsight = true;
                this.insightError = null;
                try {
                    await getApiService().saveInsight(this.insightForm);
                    this.showToast(this.t(this.insightForm.id ? 'insightUpdated' : 'insightAdded'));
                    this.insightForm = null;
                    await this.loadInsights(this.selectedMonth, this.selectedYear);
                } catch (error) {
                    // Shown in the modal, next to the query to correct
                    this.insightError = this.apiErrorText(error);
                } finally {
                    this.savingInsight = false;
                }
            },

            /**
             * Deletes an insight after confirmation
             * @param {Object} insight - Insight
             * @returns {Promise<void>}
             */
            async removeInsight(insight) {
                if (!confirm(this.t('confirmDeleteInsight', { name: this.getInsightName(insight.name) }))) {
                    return;
                }
                try {
                    await getApiService().deleteInsight(insight.id);
                    this.insights = this.insights.filter(i => Number(i.id) !== Number(insight.id));
                    this.insightForm = null;
                    this.showToast(this.t('insightDeleted'));
                } catch (error) {
                    this.showToast(this.apiErrorText(error));
                }
            },

            /**
             * Aborts any pending insights request
             */
            abortInsights() {
                if (this.insightsController && typeof this.insightsController.abort === 'function') {
                    try {
                        this.insightsController.abort();
                    } catch (e) {
                        // Ignore abort errors
                    }
                    this.insightsController = null;
                }
            }
        }
    };
}
