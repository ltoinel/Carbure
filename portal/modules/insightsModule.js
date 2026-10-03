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
                // Insight of the modal: {id, name, color, sql}
                insightForm: null,
                savingInsight: false,
                insightError: null,
                insightColors: ['red', 'green', 'blue', 'orange', 'gray'],
                insightsController: null
            };
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

                    // Show debug toast in dev mode
                    if (this.isDebugMode) {
                        this.showToast(`Insights loaded: ${this.insights.length} items`);
                    }
                } catch (err) {
                    if (err.name === 'AbortError') {
                        console.log('Insights request aborted');
                        return;
                    }
                    
                    console.error('Insights loading error:', err);
                    this.error = err.message;
                    this.insights = [];
                } finally {
                    this.loadingInsights = false;
                    this.insightsController = null;
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
                        sql: 'SELECT SUM(amount) AS amount FROM bank_transaction WHERE category = 1 AND MONTH(date) = {month} AND YEAR(date) = {year}'
                    };
                } else {
                    try {
                        const definitions = await getApiService().fetchInsightDefinitions();
                        const definition = definitions.find(d => Number(d.id) === Number(insight.id));
                        this.insightForm = { id: Number(insight.id), name: insight.name, color: insight.color, sql: definition ? definition.sql : '' };
                    } catch (error) {
                        this.showToast(error.message);
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
                    this.insightError = error.message;
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
                    this.insights = this.insights.filter(i => i.id !== insight.id);
                    this.showToast(this.t('insightDeleted'));
                } catch (error) {
                    this.showToast(error.message);
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
