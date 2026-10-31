/**
 * Split Module
 *
 * Splits a transaction across several categories. The modal allocates the whole amount of
 * the bank: its first line is the transaction itself (its category and the amount it
 * keeps), each other line a part that becomes a transaction of its own (split_of = the id
 * of the transaction). Nothing may be left to allocate, nor more than the amount.
 *
 * @module splitModule
 */

/** Most parts a transaction can be split into (Transaction::MAX_SPLIT_PARTS) */
export const MAX_SPLIT_PARTS = 10;

/**
 * Amount typed by the user, in cents (a comma is a decimal separator); 0 if not a number
 * @param {string|number} value - Amount
 * @returns {number}
 */
export function toCents(value) {
    const amount = Number(String(value ?? '').trim().replace(',', '.'));
    return Number.isFinite(amount) ? Math.round(amount * 100) : 0;
}

/**
 * Rest of the transaction once split, in cents: the parts are typed without their sign,
 * they take the one of the transaction
 * @param {number} bankAmount - Amount of the bank of the transaction
 * @param {Array<{amount: string|number}>} parts - Parts being edited
 * @returns {number}
 */
export function splitRemainder(bankAmount, parts) {
    const bank = toCents(bankAmount);
    const sign = bank < 0 ? -1 : 1;
    return parts.reduce((rest, part) => rest - sign * Math.abs(toCents(part.amount)), bank);
}

/**
 * Transactions of a list with the parts of a split one right after it (a part whose
 * transaction is not in the list stays in its place)
 * @param {Array<Object>} list - Transactions, as ordered by the API
 * @returns {Array<Object>}
 */
export function withSplitParts(list) {
    const ids = new Set(list.map(t => Number(t.id)));
    const isListedPart = t => t.split_of != null && ids.has(Number(t.split_of));
    const parts = new Map();
    for (const transaction of list.filter(isListedPart)) {
        const origin = Number(transaction.split_of);
        parts.set(origin, [...(parts.get(origin) || []), transaction]);
    }
    if (parts.size === 0) {
        return list;
    }
    return list.filter(t => !isListedPart(t)).flatMap(t => [t, ...(parts.get(Number(t.id)) || [])]);
}

/**
 * Creates the split module mixin for Vue components
 * @param {Function} getApiService - Function that returns the API service instance
 * @returns {Object} Vue mixin
 */
export function createSplitModule(getApiService) {
    return {
        data() {
            return {
                // Transaction being split (modal) and its lines [{category, amount}] typed without
                // sign: the first one is the transaction itself, the others its parts
                splitTransaction: null,
                splitParts: [],
                savingSplit: false
            };
        },

        computed: {
            /**
             * Amount of the bank of the transaction being split
             * @returns {number}
             */
            splitBankAmount() {
                return this.splitTransaction ? this.transactionBankAmount(this.splitTransaction) : 0;
            },

            /**
             * Amount left to allocate (0 once the lines add up to the amount of the bank)
             * @returns {number}
             */
            splitRest() {
                return splitRemainder(this.splitBankAmount, this.splitParts) / 100;
            },

            /**
             * Amount left to allocate as the lines are typed, without the sign of the
             * transaction (negative once the amount is exceeded)
             * @returns {number}
             */
            splitToAllocate() {
                return (toCents(this.splitBankAmount) < 0 ? -this.splitRest : this.splitRest) || 0;
            },

            /**
             * Whether the lines allocate more than the amount of the bank
             * @returns {boolean}
             */
            splitExceeded() {
                const rest = splitRemainder(this.splitBankAmount, this.splitParts);
                return rest !== 0 && (rest < 0) !== (toCents(this.splitBankAmount) < 0);
            },

            /**
             * Whether no part can be added (the first line is the transaction, not a part)
             * @returns {boolean}
             */
            splitFull() {
                return this.splitParts.length > MAX_SPLIT_PARTS;
            },

            /**
             * Whether the lines can be saved: the transaction and at least one part, each
             * one with a category and an amount, adding up to the whole amount of the bank
             * @returns {boolean}
             */
            splitValid() {
                return this.splitParts.length > 1
                    && this.splitParts.every(p => p.category !== '' && p.category !== null && toCents(p.amount) > 0)
                    && splitRemainder(this.splitBankAmount, this.splitParts) === 0;
            }
        },

        methods: {
            /**
             * Parts of a split transaction, among the transactions shown
             * @param {Object} transaction - Transaction
             * @returns {Array<Object>}
             */
            transactionParts(transaction) {
                const list = this.isSearching ? this.searchResults : this.transactions;
                return list.filter(t => t.split_of != null && Number(t.split_of) === Number(transaction.id));
            },

            /**
             * Amount of a transaction as the bank gave it: its own one plus the one of its parts
             * @param {Object} transaction - Transaction
             * @returns {number}
             */
            transactionBankAmount(transaction) {
                const cents = this.transactionParts(transaction).reduce((sum, t) => sum + toCents(t.amount), toCents(transaction.amount));
                return cents / 100;
            },

            /**
             * Opens the split of the transaction of the detail modal (of its transaction for a part)
             */
            openSplit() {
                const detail = this.transactionDetail;
                const list = this.isSearching ? this.searchResults : this.transactions;
                const transaction = detail.split_of != null
                    ? list.find(t => Number(t.id) === Number(detail.split_of)) : detail;
                if (!transaction) {
                    return;
                }
                const parts = this.transactionParts(transaction);
                const category = transaction.category === null || transaction.category === undefined ? '' : Number(transaction.category);
                // Not split yet: the transaction and an empty part, all its amount to allocate
                this.splitParts = parts.length
                    ? [{ category, amount: Math.abs(Number(transaction.amount)).toFixed(2) },
                        ...parts.map(p => ({ category: Number(p.category), amount: Math.abs(Number(p.amount)).toFixed(2) }))]
                    : [{ category, amount: '' }, { category: '', amount: '' }];
                this.transactionDetail = null;
                this.splitTransaction = transaction;
                this.$nextTick?.(() => document.querySelector('.modal-split .split-part input')?.focus());
            },

            /** Adds an empty part */
            addSplitPart() {
                if (!this.splitFull) {
                    this.splitParts.push({ category: '', amount: '' });
                }
            },

            /**
             * Removes a part (never the first line: the transaction itself)
             * @param {number} index - Index of the line
             */
            removeSplitPart(index) {
                if (index > 0) {
                    this.splitParts.splice(index, 1);
                }
            },

            /** Closes the split modal */
            closeSplit() {
                this.splitTransaction = null;
            },

            /**
             * Saves the split: the parts (the transaction keeps the rest of the amount of the
             * bank), then the category of the transaction if it changed
             * @returns {Promise<void>}
             */
            async saveSplit() {
                if (!this.splitValid) {
                    return;
                }
                const sign = toCents(this.splitBankAmount) < 0 ? -1 : 1;
                const [main, ...lines] = this.splitParts;
                const parts = lines.map(p => ({
                    category: Number(p.category),
                    amount: sign * Math.abs(toCents(p.amount)) / 100
                }));
                const category = Number(main.category);
                const recategorize = Number(this.splitTransaction.category) !== category || this.splitTransaction.category === null;
                await this.sendSplit(parts, 'splitSaved',
                    recategorize ? id => getApiService().setTransactionCategory(id, category) : null);
            },

            /**
             * Cancels the split: the transaction gets back the whole amount of the bank
             * @returns {Promise<void>}
             */
            async cancelSplit() {
                await this.sendSplit([], 'splitCancelled');
            },

            /**
             * Sends the parts of the transaction being split, then reloads the transactions
             * @param {Array<{category: number, amount: number}>} parts - Parts, with their sign
             * @param {string} message - Key of the text shown once done
             * @param {?Function} [then] - Called with the id of the transaction once split
             * @returns {Promise<void>}
             */
            async sendSplit(parts, message, then = null) {
                this.savingSplit = true;
                try {
                    await getApiService().splitTransaction(this.splitTransaction.id, parts);
                    await then?.(this.splitTransaction.id);
                    this.splitTransaction = null;
                    this.showToast(this.t(message));
                    if (this.isSearching) {
                        this.runSearch();
                    }
                    this.loadTransactions(this.selectedMonth, this.selectedYear);
                } catch (error) {
                    this.showToast(this.apiErrorText(error));
                } finally {
                    this.savingSplit = false;
                }
            }
        }
    };
}
