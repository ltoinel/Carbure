/**
 * Budget Module
 * 
 * Manages budget-related state and methods.
 * Provides functionality for budget navigation, editing, and display.
 * 
 * @module budgetModule
 */

import { materialIconFor, cssColorFor } from '../utils/categoryIcons.js';

/**
 * Known Material icon names (true) or unknown ones (false), measured once
 * @type {Map<string, boolean>}
 */
const materialIcons = new Map();

/**
 * Checks that a name is rendered as a Material icon: a known ligature is
 * about one em wide, an unknown name is rendered as plain (wide) text
 * @param {string} name - Icon name stored for the category
 * @returns {boolean}
 */
function isMaterialIcon(name) {
    if (!/^[a-z0-9_]+$/.test(name)) {
        return false;
    }
    if (materialIcons.has(name)) {
        return materialIcons.get(name);
    }
    const probe = document.createElement('span');
    probe.className = 'material-icons';
    probe.style.cssText = 'position:absolute;visibility:hidden;font-size:24px;white-space:nowrap';
    probe.textContent = name;
    document.body.appendChild(probe);
    const known = probe.offsetWidth <= 30;
    probe.remove();
    // Only cache once the icon font is loaded (before, every name is plain text)
    if (!document.fonts || document.fonts.status === 'loaded') {
        materialIcons.set(name, known);
    }
    return known;
}

/**
 * Creates a budget module mixin for Vue components
 * @param {Function} getBudgetStore - Function that returns the budget store instance
 * @returns {Object} Vue mixin with budget methods and computed properties
 */
export function createBudgetModule(getBudgetStore, getApiService) {
    return {
        data() {
            return {
                showBudgetModal: false,
                // Category whose transactions are listed under the cards: {id, name}
                budgetCategory: null,
                budgetTransactions: [],
                loadingBudgetTransactions: false,
                iconFontReady: !document.fonts || document.fonts.status === 'loaded',
                budgetToEdit: null
            };
        },

        mounted() {
            if (!this.iconFontReady) {
                document.fonts.ready.then(() => { this.iconFontReady = true; });
            }
        },

        computed: {
            /**
             * Safe access to current budget list from store
             * @returns {Array}
             */
            currentBudgetList() {
                const store = getBudgetStore();
                return store?.state?.currentList || [];
            },

            /**
             * Safe access to breadcrumb stack from store
             * @returns {Array}
             */
            breadcrumbStack() {
                const store = getBudgetStore();
                return store?.state?.breadcrumbStack || [];
            },

            /**
             * Budget items grouped for display: budgeted expenses, incomes, others
             * (off-budget categories, or nothing budgeted nor spent)
             * @returns {{expenses: Array, incomes: Array, others: Array}}
             */
            budgetGroups() {
                const groups = { expenses: [], incomes: [], others: [] };
                for (const item of this.currentBudgetList) {
                    const budget = Number(item.budget) || 0;
                    const consumed = Number(item.consummed) || 0;
                    if (item.type === 'CREDIT') {
                        groups.incomes.push(item);
                    } else if (item.type === 'HORS-BUDGET' || (budget === 0 && consumed === 0)) {
                        groups.others.push(item);
                    } else {
                        groups.expenses.push(item);
                    }
                }
                return groups;
            },

            /**
             * Totals of the budgeted expenses of the displayed level
             * @returns {{budget: number, consumed: number, remaining: number, progress: number}}
             */
            budgetSummary() {
                const items = this.budgetGroups.expenses;
                const budget = items.reduce((sum, i) => sum + (Number(i.budget) || 0), 0);
                const consumed = items.reduce((sum, i) => sum + (Number(i.consummed) || 0), 0);
                return {
                    budget,
                    consumed,
                    remaining: budget - consumed,
                    progress: budget > 0 ? Math.round(consumed / budget * 100) : 0
                };
            },

            /**
             * Loading state from budget store
             * @returns {boolean}
             */
            loadingBudget() {
                const store = getBudgetStore();
                return store?.state?.isLoading || false;
            },

            /**
             * Budget store error message
             * @returns {string|null}
             */
            budgetError() {
                const store = getBudgetStore();
                return store?.state?.error || null;
            }
        },

        methods: {
            /**
             * Loads root budgets using budget store
             * @param {number} month - Month (1-12)
             * @param {number} year - Year
             * @returns {Promise<void>}
             */
            async loadBudget(month, year) {
                const store = getBudgetStore();
                if (!store) {
                    console.error('Budget store not initialized');
                    return;
                }

                this.clearBudgetTransactions();

                try {
                    console.log('Loading budget for', month, year);
                    await store.loadRootBudgets(month, year);

                    // Show debug toast in dev mode
                    if (this.isDebugMode) {
                        this.showToast(`Budget loaded: ${this.currentBudgetList.length} items`);
                    }
                    
                    console.log('Budget loaded, items count:', this.currentBudgetList.length);
                } catch (err) {
                    console.error('Budget loading error:', err);
                    this.error = err.message;
                }
            },

            /**
             * Material icon of a category (falls back when the stored icon is not a Material name)
             * @param {Object} item - Budget item
             * @returns {string}
             */
            categoryIcon(item) {
                // Re-evaluated once the icon font is loaded
                if (!this.iconFontReady) {
                    return 'category';
                }
                // Material name, or SF Symbol of the iOS app mapped to Material
                return materialIconFor(item.icon, isMaterialIcon);
            },

            /**
             * Color of a category (falls back to the primary color)
             * @param {Object} item - Budget item
             * @returns {string}
             */
            categoryColor(item) {
                // CSS color, hex, or SwiftUI color name of the iOS app
                return cssColorFor(item.color) || 'var(--primary-color)';
            },

            /**
             * Budget status of an item: ok, near (>= 80 %) or over (> 100 %)
             * @param {Object} item - Budget item
             * @returns {string}
             */
            budgetStatus(item) {
                const progress = Number(item.progress) || 0;
                if (progress > 100) return 'over';
                if (progress >= 80) return 'near';
                return 'ok';
            },

            /**
             * Loads the transactions of a category (sub-categories included)
             * @param {Object} category - Category {id, name}
             * @param {number} month - Month (1-12)
             * @param {number} year - Year
             * @returns {Promise<void>}
             */
            async loadBudgetTransactions(category, month, year) {
                this.budgetCategory = { id: category.id, name: category.name };
                this.loadingBudgetTransactions = true;
                try {
                    this.budgetTransactions = await getApiService().fetchCategoryTransactions(month, year, category.id);
                } catch (err) {
                    this.budgetTransactions = [];
                    this.showToast(err.message);
                } finally {
                    this.loadingBudgetTransactions = false;
                }
                this.$nextTick(() => {
                    const list = document.querySelector('.budget-transactions');
                    if (list) {
                        list.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    }
                });
            },

            /**
             * Hides the transactions of the selected category
             */
            clearBudgetTransactions() {
                this.budgetCategory = null;
                this.budgetTransactions = [];
            },

            /**
             * Navigates to child budgets for a given item
             * @param {Object} item - Budget item to navigate to
             * @param {number} month - Month (1-12)
             * @param {number} year - Year
             * @returns {Promise<void>}
             */
            async handleBudgetClick(item, month, year) {
                const store = getBudgetStore();
                if (!store) {
                    console.error('Budget store not initialized');
                    return;
                }

                try {
                    // List the transactions of the category, then go into its sub-categories if any
                    this.loadBudgetTransactions(item, month, year);
                    await store.navigateToChildren(item, month, year);
                } catch (err) {
                    console.error('Navigate to budget error:', err);
                    this.showToast(err.message || 'Erreur de navigation');
                }
            },

            /**
             * Navigates to a specific breadcrumb level
             * @param {number} index - Breadcrumb index (-1 for root)
             * @param {number} month - Month (1-12)
             * @param {number} year - Year
             * @returns {Promise<void>}
             */
            async handleBreadcrumbClick(index, month, year) {
                const store = getBudgetStore();
                if (!store) {
                    console.error('Budget store not initialized');
                    return;
                }

                try {
                    await store.navigateToBreadcrumb(index, month, year);
                    const crumb = index >= 0 ? store.state.breadcrumbStack[index] : null;
                    if (crumb) {
                        this.loadBudgetTransactions(crumb, month, year);
                    } else {
                        this.clearBudgetTransactions();
                    }
                } catch (err) {
                    console.error('Breadcrumb navigation error:', err);
                    this.showToast(err.message || 'Erreur de navigation');
                }
            },

            /**
             * Opens budget edit modal
             * @param {Object} item - Budget item to edit
             */
            handleBudgetEdit(item) {
                this.budgetToEdit = item;
                this.showBudgetModal = true;
            },

            /**
             * Closes budget edit modal
             */
            closeBudgetModal() {
                this.showBudgetModal = false;
                this.budgetToEdit = null;
            },

            /**
             * Saves edited budget value
             * @param {number} newValue - New budget amount
             * @param {number} month - Month (1-12)
             * @param {number} year - Year
             * @returns {Promise<void>}
             */
            async handleBudgetSave(newValue, month, year) {
                const store = getBudgetStore();
                if (!store) {
                    console.error('Budget store not initialized');
                    return;
                }

                try {
                    await store.updateBudget(
                        this.budgetToEdit.id,
                        month,
                        year,
                        newValue
                    );

                    this.showToast(this.t('budgetUpdatedToast') || 'Budget mis à jour');
                    this.closeBudgetModal();
                } catch (err) {
                    console.error('Edit budget error:', err);
                    alert(err.message || 'Erreur lors de la mise à jour du budget');
                }
            },

            /**
             * Aborts any pending budget request
             */
            abortBudget() {
                const store = getBudgetStore();
                if (store && typeof store.abortAll === 'function') {
                    store.abortAll();
                }
            }
        }
    };
}
