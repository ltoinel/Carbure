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
