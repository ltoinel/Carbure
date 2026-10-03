/**
 * Rules Module
 *
 * Manages the automatic categorization rules: a keyword found in a transaction
 * label sets its category.
 *
 * @module rulesModule
 */

/**
 * Creates a rules module mixin for Vue components
 * @param {Function} getApiService - Function that returns the API service instance
 * @returns {Object} Vue mixin with rules data and methods
 */
export function createRulesModule(getApiService) {
    return {
        data() {
            return {
                rules: [],
                ruleCategories: [],
                loadingRules: false,
                ruleFilter: '',
                newRule: { keyword: '', category: '' },
                savingRule: false,
                applyingRules: false
            };
        },

        computed: {
            /**
             * Categories as options: parents first, each followed by its sub-categories
             * @returns {Array<{id: number, label: string}>}
             */
            ruleCategoryOptions() {
                const parents = this.ruleCategories.filter(c => Number(c.parent_category) === 0 && Number(c.id) !== 0);
                const options = [];
                const byName = (a, b) => a.name.localeCompare(b.name);
                for (const parent of parents.sort(byName)) {
                    options.push({ id: parent.id, label: parent.name });
                    this.ruleCategories
                        .filter(c => Number(c.parent_category) === Number(parent.id) && Number(c.id) !== Number(parent.id))
                        .sort(byName)
                        .forEach(child => options.push({ id: child.id, label: `${parent.name} › ${child.name}` }));
                }
                return options;
            },

            /**
             * Rules matching the filter (keyword or category), grouped by category
             * @returns {Array<{category: string, rules: Array}>}
             */
            groupedRules() {
                const filter = this.ruleFilter.trim().toUpperCase();
                const groups = new Map();
                for (const rule of this.rules) {
                    if (filter && !rule.keyword.includes(filter) && !rule.category_name.toUpperCase().includes(filter)) {
                        continue;
                    }
                    if (!groups.has(rule.category_name)) {
                        groups.set(rule.category_name, []);
                    }
                    groups.get(rule.category_name).push(rule);
                }
                return [...groups]
                    .map(([category, rules]) => ({ category, rules }))
                    .sort((a, b) => a.category.localeCompare(b.category, this.locale));
            }
        },

        methods: {
            /**
             * Loads the rules and the categories
             * @returns {Promise<void>}
             */
            async loadRules() {
                const apiService = getApiService();
                if (!apiService) {
                    return;
                }

                this.loadingRules = true;
                try {
                    [this.rules, this.ruleCategories] = await Promise.all([
                        apiService.fetchRules(),
                        apiService.fetchCategories()
                    ]);
                } catch (error) {
                    this.error = error.message;
                } finally {
                    this.loadingRules = false;
                }
            },

            /**
             * Adds the rule of the form
             * @returns {Promise<void>}
             */
            async addRule() {
                if (!this.newRule.keyword.trim() || this.newRule.category === '') {
                    this.showToast(this.t('ruleRequired'));
                    return;
                }

                this.savingRule = true;
                try {
                    await getApiService().createRule(this.newRule.keyword, this.newRule.category);
                    this.newRule.keyword = '';
                    this.showToast(this.t('ruleAdded'));
                    this.rules = await getApiService().fetchRules();
                } catch (error) {
                    this.showToast(error.message);
                } finally {
                    this.savingRule = false;
                }
            },

            /**
             * Deletes a rule after confirmation
             * @param {Object} rule - Rule to delete
             * @returns {Promise<void>}
             */
            async removeRule(rule) {
                if (!confirm(this.t('confirmDeleteRule', { keyword: rule.keyword }))) {
                    return;
                }

                try {
                    await getApiService().deleteRule(rule.id);
                    this.rules = this.rules.filter(r => r.id !== rule.id);
                    this.showToast(this.t('ruleDeleted'));
                } catch (error) {
                    this.showToast(error.message);
                }
            },

            /**
             * Applies the rules to the transactions without category
             * @returns {Promise<void>}
             */
            async applyRules() {
                this.applyingRules = true;
                try {
                    const result = await getApiService().applyRules();
                    this.showToast(this.t('rulesApplied', { count: result.updated }));
                } catch (error) {
                    this.showToast(error.message);
                } finally {
                    this.applyingRules = false;
                }
            }
        }
    };
}
