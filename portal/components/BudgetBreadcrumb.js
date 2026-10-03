/**
 * BudgetBreadcrumb Component
 * 
 * Displays a navigation breadcrumb trail for the budget hierarchy.
 * Allows users to navigate back to any parent level or the root.
 * 
 * @component
 * @example
 * <budget-breadcrumb 
 *   :stack="budgetStack" 
 *   @navigate="handleBreadcrumbClick">
 * </budget-breadcrumb>
 */
export default {
    name: 'BudgetBreadcrumb',
    
    props: {
        /**
         * Array of breadcrumb items representing the navigation path
         * @type {Array<{id: number, name: string}>}
         */
        stack: {
            type: Array,
            required: true,
            default: () => []
        }
    },
    
    emits: ['navigate'],
    
    methods: {
        /**
         * Handles breadcrumb navigation clicks
         * Emits navigate event with the target index (-1 for root)
         * @param {number} index - Target breadcrumb index
         */
        handleNavigate(index) {
            this.$emit('navigate', index);
        }
    },
    
    template: `
        <div class="budget-breadcrumb">
            <button class="breadcrumb-root" @click="handleNavigate(-1)">
                <span class="material-icons">home</span>
                {{ $root.t('tabBudget') }}
            </button>
            <template v-for="(crumb, idx) in stack" :key="crumb.id">
                <span class="breadcrumb-sep">/</span>
                <button class="breadcrumb-item" @click="handleNavigate(idx)">
                    {{ crumb.name }}
                </button>
            </template>
        </div>
    `
};
