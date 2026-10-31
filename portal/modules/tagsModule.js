/**
 * Tags Module
 *
 * Tags of a project (a trip, works...) limited in time: a second axis, apart from the
 * categories. A transaction keeps its category and gets tags by hand, from its detail
 * or on a selection of the list. A tag is active until its end date, then archived:
 * no more offered, its summary (net cost, by category, day by day) still readable.
 *
 * @module tagsModule
 */

import { isoDay } from './manualModule.js';

/** Most transactions tagged at once (Tag::MAX_SELECTION) */
export const MAX_TAG_SELECTION = 500;

/**
 * Name of a tag as stored: without its #, in lower case, without spaces around
 * @param {string} input - Name typed
 * @returns {string}
 */
export function tagName(input) {
    return String(input ?? '').trim().replace(/^#+/, '').toLowerCase();
}

/**
 * Whether a tag is still offered: its end date is not past
 * @param {{end_date: string|null}} tag - Tag
 * @param {string} today - Today (YYYY-MM-DD)
 * @returns {'active'|'archived'}
 */
export function tagStatus(tag, today) {
    return !tag.end_date || tag.end_date >= today ? 'active' : 'archived';
}

/**
 * Tags to add and to remove to go from a list of tags to another one
 * @param {Array<number>} before - Ids of the tags before
 * @param {Array<number>} after - Ids of the tags wanted
 * @returns {{add: Array<number>, remove: Array<number>}}
 */
export function tagChanges(before, after) {
    const was = new Set((before || []).map(Number));
    const wanted = new Set((after || []).map(Number));
    return {
        add: [...wanted].filter(id => !was.has(id)),
        remove: [...was].filter(id => !wanted.has(id))
    };
}

/**
 * Points of the running total of a tag, in a box: the first day on the left, the last
 * one on the right, zero included in the vertical scale
 * @param {Array<{date: string, cumulative: number}>} days - Days, oldest first
 * @param {number} width - Width of the box
 * @param {number} height - Height of the box
 * @returns {{points: Array<{x: number, y: number, date: string, cumulative: number}>, path: string, zero: number}}
 */
export function cumulativePath(days, width, height) {
    if (!days || days.length === 0) {
        return { points: [], path: '', zero: height };
    }
    const values = days.map(d => Number(d.cumulative));
    const min = Math.min(0, ...values);
    const max = Math.max(0, ...values);
    const span = max - min || 1;
    const time = d => new Date(`${d.date}T00:00:00`).getTime();
    const first = time(days[0]);
    const range = time(days[days.length - 1]) - first || 1;
    const y = value => Math.round((height - ((value - min) / span) * height) * 10) / 10;
    const points = days.map(d => ({
        x: days.length === 1 ? width / 2 : Math.round(((time(d) - first) / range) * width * 10) / 10,
        y: y(Number(d.cumulative)),
        date: d.date,
        cumulative: Number(d.cumulative)
    }));
    return { points, path: points.map((p, i) => `${i ? 'L' : 'M'}${p.x},${p.y}`).join(' '), zero: y(0) };
}

/** Form of a tag, empty */
const EMPTY_TAG = { id: null, name: '', color: 'blue', start_date: '', end_date: '' };

/**
 * Creates the tags module mixin for Vue components
 * @param {Function} getApiService - Function that returns the API service instance
 * @returns {Object} Vue mixin
 */
export function createTagsModule(getApiService) {
    return {
        data() {
            return {
                // Every tag: [{id, name, color, start_date, end_date, active, count, total}]
                tags: [],
                loadingTags: false,
                // Tag being created or changed (modal)
                showTagModal: false,
                tagForm: { ...EMPTY_TAG },
                savingTag: false,
                // Summary of the tag opened in the Tags tab
                tagSummary: null,
                // Selection of transactions of the list, to tag them at once
                selectingTransactions: false,
                selectedTransactionIds: [],
                // Tag chosen in the selection bar, applied by its button
                selectionTag: ''
            };
        },

        computed: {
            /**
             * Tags still offered (their end is not past), by name
             * @returns {Array<Object>}
             */
            activeTags() {
                return this.tags.filter(t => t.active).sort((a, b) => a.name.localeCompare(b.name));
            },

            /**
             * Tags archived, most recent first
             * @returns {Array<Object>}
             */
            archivedTags() {
                return this.tags.filter(t => !t.active);
            },

            /**
             * Tags by id
             * @returns {Map<number, Object>}
             */
            tagsById() {
                return new Map(this.tags.map(t => [Number(t.id), t]));
            },

            /**
             * Running total of the tag opened, drawn in a 600×160 box
             * @returns {Object}
             */
            tagSummaryChart() {
                return cumulativePath(this.tagSummary?.days || [], 600, 160);
            },

            /**
             * Largest absolute total of the categories of the tag opened (scale of their bars)
             * @returns {number}
             */
            tagSummaryMax() {
                return Math.max(1, ...(this.tagSummary?.categories || []).map(c => Math.abs(c.total)));
            }
        },

        methods: {
            /**
             * Loads the tags
             * @returns {Promise<void>}
             */
            async loadTags() {
                if (!getApiService()) {
                    return;
                }
                this.loadingTags = true;
                try {
                    this.tags = await getApiService().fetchTags();
                } catch (error) {
                    this.showToast(this.apiErrorText(error));
                } finally {
                    this.loadingTags = false;
                }
            },

            /**
             * Tags of a transaction
             * @param {Object} transaction - Transaction (tags: ids)
             * @returns {Array<Object>}
             */
            transactionTags(transaction) {
                return (transaction.tags || []).map(id => this.tagsById.get(Number(id))).filter(Boolean);
            },

            /**
             * Active tags not on a transaction yet, offered in its detail
             * @param {Object} transaction - Transaction
             * @returns {Array<Object>}
             */
            tagsToOffer(transaction) {
                const on = new Set((transaction.tags || []).map(Number));
                return this.activeTags.filter(t => !on.has(Number(t.id)));
            },

            /**
             * Opens the form of a tag: a new one (starting today), or one to change
             * @param {Object|null} tag - Tag to change
             */
            openTagModal(tag = null) {
                this.tagForm = tag
                    ? { id: tag.id, name: tag.name, color: tag.color || '', start_date: tag.start_date, end_date: tag.end_date || '' }
                    : { ...EMPTY_TAG, start_date: isoDay(new Date()) };
                this.showTagModal = true;
                this.$nextTick?.(() => document.querySelector('#tag-name')?.focus());
            },

            /** Closes the form of a tag */
            closeTagModal() {
                this.showTagModal = false;
            },

            /**
             * Saves the tag of the form, then reloads the tags (and the summary opened)
             * @returns {Promise<void>}
             */
            async saveTag() {
                const form = this.tagForm;
                const tag = { name: tagName(form.name), color: form.color, start_date: form.start_date, end_date: form.end_date || null };
                this.savingTag = true;
                try {
                    if (form.id === null) {
                        await getApiService().createTag(tag);
                    } else {
                        await getApiService().updateTag({ id: form.id, ...tag });
                    }
                    this.showTagModal = false;
                    this.showToast(this.t('tagSaved'));
                    await this.loadTags();
                    if (this.tagSummary && Number(this.tagSummary.tag.id) === Number(form.id)) {
                        await this.openTagSummary(this.tagSummary.tag);
                    }
                } catch (error) {
                    this.showToast(this.apiErrorText(error));
                } finally {
                    this.savingTag = false;
                }
            },

            /**
             * Deletes a tag after confirmation: its transactions only lose it
             * @param {Object} tag - Tag
             * @returns {Promise<void>}
             */
            async removeTag(tag) {
                if (!confirm(this.t('confirmDeleteTag', { name: tag.name }))) {
                    return;
                }
                try {
                    await getApiService().deleteTag(tag.id);
                    this.showToast(this.t('tagDeleted'));
                    if (this.tagSummary && Number(this.tagSummary.tag.id) === Number(tag.id)) {
                        this.tagSummary = null;
                    }
                    await this.loadTags();
                } catch (error) {
                    this.showToast(this.apiErrorText(error));
                }
            },

            /**
             * Opens the summary of a tag
             * @param {Object} tag - Tag
             * @returns {Promise<void>}
             */
            async openTagSummary(tag) {
                this.ensureCategories?.();
                try {
                    this.tagSummary = await getApiService().fetchTagSummary(tag.id);
                    this.$nextTick?.(() => document.querySelector('.tag-summary h3')?.focus());
                } catch (error) {
                    this.showToast(this.apiErrorText(error));
                }
            },

            /**
             * A category, by its id (bars of the summary)
             * @param {number} id - Category id
             * @returns {Object}
             */
            categoryOf(id) {
                return (this.ruleCategories || []).find(c => Number(c.id) === Number(id)) || { id, name: '', color: '' };
            },

            /** Back to the list of the tags */
            closeTagSummary() {
                this.tagSummary = null;
            },

            /**
             * Shows the transactions of a tag in the Transactions tab (search "#name")
             * @param {Object} tag - Tag
             */
            showTagTransactions(tag) {
                this.setActiveTab('transactions');
                this.searchQuery = `#${tag.name}`;
                this.runSearch();
            },

            /**
             * Sets the tags of a transaction and updates it in place
             * @param {Object} transaction - Transaction
             * @param {Array<number>} wanted - Ids of its tags
             * @returns {Promise<void>}
             */
            async setTransactionTags(transaction, wanted) {
                const { add, remove } = tagChanges(transaction.tags, wanted);
                if (!add.length && !remove.length) {
                    return;
                }
                try {
                    await getApiService().setTransactionTags([transaction.id], add, remove);
                    transaction.tags = wanted.map(Number);
                    this.loadTags();
                } catch (error) {
                    this.showToast(this.apiErrorText(error));
                }
            },

            /**
             * Creates a tag typed in a tag field: starting today, with the default color
             * @param {string} name - Name of the tag
             * @returns {Promise<Object|null>} The tag, or null when it could not be created
             */
            async createTypedTag(name) {
                try {
                    const tag = await getApiService().createTag({ name: tagName(name), color: EMPTY_TAG.color, start_date: isoDay(new Date()) });
                    this.tags = [...this.tags, tag];
                    this.showToast(this.t('tagCreated', { name: tag.name }));
                    return tag;
                } catch (error) {
                    this.showToast(this.apiErrorText(error));
                    return null;
                }
            },

            /**
             * Adds a tag chosen in the detail of a transaction
             * @param {Object} transaction - Transaction
             * @param {number} id - Tag id
             * @returns {Promise<void>}
             */
            async addDetailTag(transaction, id) {
                if (!id || (transaction.tags || []).map(Number).includes(Number(id))) {
                    return;
                }
                await this.setTransactionTags(transaction, [...(transaction.tags || []), Number(id)]);
            },

            /**
             * Creates a tag typed in the detail of a transaction, then adds it
             * @param {Object} transaction - Transaction
             * @param {string} name - Name of the tag
             * @returns {Promise<void>}
             */
            async createDetailTag(transaction, name) {
                const tag = await this.createTypedTag(name);
                if (tag) {
                    await this.addDetailTag(transaction, Number(tag.id));
                }
            },

            /**
             * Removes a tag from a transaction
             * @param {Object} transaction - Transaction
             * @param {Object} tag - Tag
             * @returns {Promise<void>}
             */
            async removeDetailTag(transaction, tag) {
                await this.setTransactionTags(transaction, (transaction.tags || []).filter(id => Number(id) !== Number(tag.id)));
            },

            /** Starts or stops the selection of transactions of the list */
            toggleTransactionSelection() {
                this.selectingTransactions = !this.selectingTransactions;
                this.selectedTransactionIds = [];
                this.selectionTag = '';
            },

            /**
             * Selects or unselects a transaction of the list
             * @param {Object} transaction - Transaction
             */
            toggleSelectedTransaction(transaction) {
                const id = Number(transaction.id);
                this.selectedTransactionIds = this.selectedTransactionIds.includes(id)
                    ? this.selectedTransactionIds.filter(i => i !== id)
                    : [...this.selectedTransactionIds, id].slice(0, MAX_TAG_SELECTION);
            },

            /**
             * Adds a tag to the selected transactions, then ends the selection
             * @param {number} id - Tag id
             * @returns {Promise<void>}
             */
            async tagSelectedTransactions(id) {
                const tag = Number(id);
                const ids = this.selectedTransactionIds;
                if (!tag || !ids.length) {
                    return;
                }
                try {
                    await getApiService().setTransactionTags(ids, [tag], []);
                    const list = this.isSearching ? this.searchResults : this.transactions;
                    for (const transaction of list.filter(t => ids.includes(Number(t.id)))) {
                        transaction.tags = [...new Set([...(transaction.tags || []).map(Number), tag])];
                    }
                    this.showToast(this.t('tagAddedToSelection', { count: ids.length, name: this.tagsById.get(tag)?.name || '' }));
                    this.selectingTransactions = false;
                    this.selectedTransactionIds = [];
                    this.selectionTag = '';
                    this.loadTags();
                } catch (error) {
                    this.showToast(this.apiErrorText(error));
                }
            },

            /**
             * Creates a tag typed in the selection bar: it becomes the tag chosen, applied by
             * the button of the bar
             * @param {string} name - Name of the tag
             * @returns {Promise<void>}
             */
            async createSelectionTag(name) {
                const tag = await this.createTypedTag(name);
                this.selectionTag = tag ? Number(tag.id) : '';
            }
        }
    };
}
