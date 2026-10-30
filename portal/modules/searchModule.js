/**
 * Search Module
 *
 * Advanced search of the Transactions tab: a modal adds criteria to the search by label
 * (amount, expenses or incomes, categories, accounts, period), shown as removable chips
 * under the toolbar. The search itself (runSearch, clearSearch) is in transactionModule.
 *
 * @module searchModule
 */

/** Criteria of the advanced search, none set */
export const EMPTY_FILTERS = Object.freeze({ min: '', max: '', kind: '', from: '', to: '', categories: [], accounts: [] });

/**
 * Number of criteria set (each category and each account counts)
 * @param {Object} filters - Criteria
 * @returns {number}
 */
export function filterCount(filters) {
    if (!filters) {
        return 0;
    }
    const set = ['min', 'max', 'kind', 'from', 'to'].filter(key => String(filters[key] ?? '').trim() !== '').length;
    return set + (filters.categories || []).length + (filters.accounts || []).length;
}

/**
 * Parameters of GET /transaction/search: the text from 2 characters, the criteria set
 * @param {string} query - Text typed
 * @param {Object} filters - Criteria
 * @returns {Object<string, string>}
 */
export function searchParams(query, filters = EMPTY_FILTERS) {
    const params = {};
    const text = String(query || '').trim();
    if (text.length >= 2) {
        params.query = text;
    }
    for (const key of ['min', 'max', 'kind', 'from', 'to']) {
        const value = String(filters[key] ?? '').trim();
        if (value !== '') {
            params[key] = key === 'min' || key === 'max' ? value.replace(',', '.') : value;
        }
    }
    for (const key of ['categories', 'accounts']) {
        if ((filters[key] || []).length) {
            params[key] = filters[key].join(',');
        }
    }
    return params;
}

/**
 * Categories offered by the modal: each top-level one followed by its sub-categories,
 * the default category first
 * @param {Array} categories - Categories {id, name, parent_category}
 * @param {string} locale - Locale of the sort
 * @returns {Array<{id: number, name: string, child: boolean}>}
 */
export function categoryOptions(categories, locale) {
    const byName = (a, b) => a.name.localeCompare(b.name, locale);
    const isTop = c => Number(c.parent_category) === 0 || Number(c.id) === 0;
    const tops = (categories || []).filter(isTop)
        .sort((a, b) => Number(a.id) === 0 ? -1 : Number(b.id) === 0 ? 1 : byName(a, b));
    return tops.flatMap(top => [
        { id: Number(top.id), name: top.name, child: false },
        ...(Number(top.id) === 0 ? [] : categories
            .filter(c => !isTop(c) && Number(c.parent_category) === Number(top.id))
            .sort(byName)
            .map(c => ({ id: Number(c.id), name: c.name, child: true })))
    ]);
}

/**
 * Creates the search module mixin for Vue components
 * @param {Function} getApiService - Function that returns the API service instance
 * @returns {Object} Vue mixin
 */
export function createSearchModule(getApiService) {
    return {
        data() {
            return {
                // Criteria of the search shown, and the ones being edited in the modal (with the text)
                searchFilters: { ...EMPTY_FILTERS },
                searchDraft: null,
                // Accounts of the household offered by the modal
                searchAccounts: []
            };
        },

        computed: {
            /**
             * Number of criteria of the search shown
             * @returns {number}
             */
            searchFilterCount() {
                return filterCount(this.searchFilters);
            },

            /**
             * Categories offered by the modal
             * @returns {Array<{id: number, name: string, child: boolean}>}
             */
            searchCategoryOptions() {
                return categoryOptions(this.ruleCategories, this.intlLocale);
            },

            /**
             * Chips of the criteria of the search shown, each one removable
             * @returns {Array<{key: string, value: *, label: string}>}
             */
            searchFilterChips() {
                const filters = this.searchFilters;
                const chips = [];
                if (filters.kind) {
                    chips.push({ key: 'kind', label: this.t(filters.kind === 'debit' ? 'advancedSearchExpenses' : 'advancedSearchIncomes') });
                }
                const amount = this.searchAmountLabel(filters.min, filters.max);
                if (amount) {
                    chips.push({ key: 'amount', label: amount });
                }
                const period = this.searchPeriodLabel(filters.from, filters.to);
                if (period) {
                    chips.push({ key: 'period', label: period });
                }
                for (const id of filters.categories) {
                    const category = (this.ruleCategories || []).find(c => Number(c.id) === Number(id));
                    chips.push({ key: 'categories', value: id, label: category ? category.name : String(id) });
                }
                for (const bankId of filters.accounts) {
                    const account = this.searchAccounts.find(a => a.bankId === bankId);
                    chips.push({ key: 'accounts', value: bankId, label: account ? this.searchAccountName(account) : this.bankIdName(bankId) });
                }
                return chips;
            }
        },

        methods: {
            /**
             * Text of the bounds of the amount ('' when none)
             * @param {string} min - Smallest amount
             * @param {string} max - Largest amount
             * @returns {string}
             */
            searchAmountLabel(min, max) {
                const money = value => `${this.formatAmount(Number(String(value).replace(',', '.')))} ${this.currencySymbol}`;
                const hasMin = String(min ?? '').trim() !== '';
                const hasMax = String(max ?? '').trim() !== '';
                if (hasMin && hasMax) {
                    return this.t('advancedSearchAmountBetween', { min: money(min), max: money(max) });
                }
                if (hasMin || hasMax) {
                    return this.t(hasMin ? 'advancedSearchAmountMin' : 'advancedSearchAmountMax', { amount: money(hasMin ? min : max) });
                }
                return '';
            },

            /**
             * Text of the period ('' when none)
             * @param {string} from - First day
             * @param {string} to - Last day
             * @returns {string}
             */
            searchPeriodLabel(from, to) {
                if (from && to) {
                    return this.t('advancedSearchPeriodBetween', { from: this.formatDate(from), to: this.formatDate(to) });
                }
                if (from || to) {
                    return this.t(from ? 'advancedSearchPeriodFrom' : 'advancedSearchPeriodTo', { date: this.formatDate(from || to) });
                }
                return '';
            },

            /**
             * Name of an account in the modal and the chips
             * @param {Object} account - Account {label, bank_name, account_number}
             * @returns {string}
             */
            searchAccountName(account) {
                return account.label || this.accountName(account);
            },

            /**
             * Opens the advanced search with the text and the criteria of the search shown
             * @returns {Promise<void>}
             */
            async openAdvancedSearch() {
                this.searchDraft = {
                    query: this.searchQuery,
                    ...this.searchFilters,
                    categories: [...this.searchFilters.categories],
                    accounts: [...this.searchFilters.accounts]
                };
                this.ensureCategories();
                this.$nextTick?.(() => document.querySelector('#advanced-search-query')?.focus());
                try {
                    this.searchAccounts = await getApiService().fetchAccounts();
                } catch (error) {
                    this.searchAccounts = [];
                    this.showToast(this.apiErrorText(error));
                }
            },

            /**
             * Closes the advanced search without changing the search shown
             */
            closeAdvancedSearch() {
                this.searchDraft = null;
            },

            /**
             * Empties the text and the criteria of the modal
             */
            resetAdvancedSearch() {
                this.searchDraft = { query: '', ...EMPTY_FILTERS, categories: [], accounts: [] };
            },

            /**
             * Searches with the text and the criteria of the modal (no text nor criterion:
             * back to the month)
             * @returns {Promise<void>}
             */
            async applyAdvancedSearch() {
                const { query, ...filters } = this.searchDraft;
                this.searchDraft = null;
                this.searchFilters = filters;
                this.searchQuery = query;
                if (!this.isSearching) {
                    this.clearSearch();
                    return;
                }
                clearTimeout(this.searchTimer);
                await this.runSearch();
            },

            /**
             * Removes a criterion of the search shown, then searches again
             * @param {{key: string, value: *}} chip - Chip of the criterion
             * @returns {Promise<void>}
             */
            async removeSearchFilter(chip) {
                const keys = { amount: ['min', 'max'], period: ['from', 'to'], kind: ['kind'] };
                const filters = { ...this.searchFilters };
                if (keys[chip.key]) {
                    keys[chip.key].forEach(key => { filters[key] = ''; });
                } else {
                    filters[chip.key] = filters[chip.key].filter(value => value !== chip.value);
                }
                this.searchFilters = filters;
                if (!this.isSearching) {
                    this.clearSearch();
                    return;
                }
                await this.runSearch();
            }
        }
    };
}
