/**
 * CategoryPicker Component
 *
 * Category selector showing each category with its icon, color, parent and
 * type, grouped by type (expenses, income, off-budget), with a search field.
 *
 * @component
 * @example
 * <category-picker v-model="rule.category" :categories="ruleCategories" :t="t"
 *   :icon-for="categoryIcon" :color-for="categoryColor"></category-picker>
 */

/** Display order of the category types */
const TYPES = ['DEBIT', 'CREDIT', 'HORS-BUDGET'];

export default {
    name: 'CategoryPicker',

    props: {
        /** Category id (number), or '' when none is chosen */
        modelValue: { type: [Number, String], default: '' },
        /** All the categories: {id, name, parent_category, type, icon, color} */
        categories: { type: Array, required: true },
        /** Translation function of the app */
        t: { type: Function, required: true },
        /** Material icon of a category */
        iconFor: { type: Function, required: true },
        /** CSS color of a category */
        colorFor: { type: Function, required: true },
        /** Offer the default category "uncategorized" (0) */
        withDefault: { type: Boolean, default: false }
    },

    emits: ['update:modelValue'],

    data() {
        return { open: false, search: '' };
    },

    computed: {
        /** Categories by id */
        byId() {
            return new Map(this.categories.map(c => [Number(c.id), c]));
        },

        /** Chosen category */
        selected() {
            return this.modelValue === '' || this.modelValue === null ? null : this.byId.get(Number(this.modelValue)) || null;
        },

        /** Parent of the chosen category (null for a top-level category) */
        selectedParent() {
            const parent = this.selected ? Number(this.selected.parent_category) : 0;
            return parent !== 0 && parent !== Number(this.selected.id) ? this.byId.get(parent) || null : null;
        },

        /**
         * Categories matching the search, grouped by type: each parent followed by its sub-categories
         * @returns {Array<{type: string, items: Array<{category: Object, parent: Object|null}>}>}
         */
        groups() {
            const search = this.search.trim().toLowerCase();
            const byName = (a, b) => a.name.localeCompare(b.name);
            const isChild = c => Number(c.parent_category) !== 0 && Number(c.parent_category) !== Number(c.id);
            const usable = this.categories.filter(c => this.withDefault || Number(c.id) !== 0);
            const matches = (c, parent) => !search || c.name.toLowerCase().includes(search)
                || (parent && parent.name.toLowerCase().includes(search));

            return TYPES.map(type => {
                const items = [];
                for (const parent of usable.filter(c => !isChild(c) && c.type === type).sort(byName)) {
                    if (matches(parent, null)) {
                        items.push({ category: parent, parent: null });
                    }
                    usable.filter(c => isChild(c) && Number(c.parent_category) === Number(parent.id))
                        .sort(byName)
                        .filter(child => matches(child, parent))
                        .forEach(child => items.push({ category: child, parent }));
                }
                // Sub-categories whose type differs from their parent's
                usable.filter(c => isChild(c) && c.type === type && this.byId.get(Number(c.parent_category))?.type !== type)
                    .sort(byName)
                    .forEach(child => {
                        const parent = this.byId.get(Number(child.parent_category)) || null;
                        if (matches(child, parent)) {
                            items.push({ category: child, parent });
                        }
                    });
                return { type, items };
            }).filter(group => group.items.length);
        }
    },

    mounted() {
        this.onDocumentClick = event => {
            if (this.open && !this.$el.contains(event.target)) {
                this.open = false;
            }
        };
        document.addEventListener('click', this.onDocumentClick);
    },

    beforeUnmount() {
        document.removeEventListener('click', this.onDocumentClick);
    },

    methods: {
        /** Opens or closes the list */
        toggle() {
            this.open = !this.open;
            if (this.open) {
                this.search = '';
                this.$nextTick(() => this.$refs.search?.focus());
            }
        },

        /**
         * Chooses a category
         * @param {Object} category - Category
         */
        choose(category) {
            this.$emit('update:modelValue', Number(category.id));
            this.open = false;
            this.$refs.button?.focus();
        },

        /**
         * Style of the icon badge of a category
         * @param {Object} category - Category
         * @returns {Object}
         */
        badgeStyle(category) {
            const color = this.colorFor(category);
            return { color, background: `color-mix(in srgb, ${color} 14%, transparent)` };
        },

        /** Closes the list (Escape) */
        close() {
            if (this.open) {
                this.open = false;
                this.$refs.button?.focus();
            }
        }
    },

    template: `
        <div class="category-picker" :class="{ open }" @keydown.esc.stop="close">
            <button ref="button" type="button" class="category-picker-button" @click="toggle"
                    aria-haspopup="listbox" :aria-expanded="open ? 'true' : 'false'">
                <template v-if="selected">
                    <span class="category-badge" :style="badgeStyle(selected)">
                        <span class="material-icons">{{ iconFor(selected) }}</span>
                    </span>
                    <span class="category-picker-name">
                        <small v-if="selectedParent">{{ selectedParent.name }} ›</small>
                        {{ selected.name }}
                    </span>
                    <span class="category-type-chip" :class="'type-' + selected.type.toLowerCase()">{{ t('categoryType_' + selected.type) }}</span>
                </template>
                <span v-else class="category-picker-placeholder">{{ t('ruleCategoryPlaceholder') }}</span>
                <span class="material-icons category-picker-arrow">expand_more</span>
            </button>
            <div v-if="open" class="category-picker-panel">
                <div class="category-picker-search">
                    <span class="material-icons">search</span>
                    <input ref="search" type="search" v-model="search" :placeholder="t('searchCategory')" :aria-label="t('searchCategory')" />
                </div>
                <div class="category-picker-list" role="listbox">
                    <div v-for="group in groups" :key="group.type" class="category-picker-group">
                        <div class="category-picker-group-title" :class="'type-' + group.type.toLowerCase()">{{ t('categoryTypes_' + group.type) }}</div>
                        <button v-for="item in group.items" :key="item.category.id" type="button" role="option"
                                class="category-picker-option" :class="{ child: item.parent, selected: selected && Number(selected.id) === Number(item.category.id) }"
                                :aria-selected="selected && Number(selected.id) === Number(item.category.id) ? 'true' : 'false'"
                                @click="choose(item.category)">
                            <span class="category-badge" :style="badgeStyle(item.category)">
                                <span class="material-icons">{{ iconFor(item.category) }}</span>
                            </span>
                            <span class="category-picker-name">
                                <small v-if="item.parent">{{ item.parent.name }} ›</small>
                                {{ item.category.name }}
                            </span>
                        </button>
                    </div>
                    <div v-if="!groups.length" class="category-picker-empty">{{ t('noCategoryFound') }}</div>
                </div>
            </div>
        </div>
    `
};
