/**
 * BudgetItem Component
 * 
 * Displays a single budget item with its consumption, allocated budget,
 * progress bar, and action buttons.
 * 
 * @component
 * @example
 * <budget-item 
 *   :item="budgetData" 
 *   @click="navigateToChildren"
 *   @edit="openEditModal">
 * </budget-item>
 */
export default {
    name: 'BudgetItem',
    
    props: {
        /**
         * Budget data object
         * @type {Object}
         * @property {number} id - Budget identifier
         * @property {string} name - Budget category name
         * @property {number} budget - Allocated budget amount
         * @property {number} consummed - Consumed amount
         * @property {number} progress - Consumption percentage (0-100+)
         */
        item: {
            type: Object,
            required: true
        }
    },
    
    emits: ['click', 'edit'],
    
    computed: {
        /**
         * Determines the progress status based on consumption percentage
         * @returns {'ok'|'near-limit'|'over-budget'}
         */
        progressStatus() {
            if (this.item.progress > 100) return 'over-budget';
            if (this.item.progress >= 90) return 'near-limit';
            return 'ok';
        },
        
        /**
         * Returns the appropriate Material icon based on progress status
         * @returns {string} Material icon name
         */
        progressIcon() {
            if (this.item.progress > 100) return 'error';
            if (this.item.progress > 80) return 'warning';
            return 'check_circle';
        },
        
        /**
         * Returns the status message translation key
         * @returns {string}
         */
        statusMessage() {
            if (this.item.progress > 100) return this.$root.t('overBudget');
            if (this.item.progress > 80) return this.$root.t('nearLimit');
            return this.$root.t('onTrack');
        }
    },
    
    methods: {
        /**
         * Handles click on budget item (for navigation)
         */
        handleClick() {
            this.$emit('click', this.item);
        },
        
        /**
         * Handles edit button click
         * Stops propagation to prevent navigation
         * @param {Event} event
         */
        handleEdit(event) {
            event.stopPropagation();
            this.$emit('edit', this.item);
        },
        
        /**
         * Formats monetary amount based on locale
         * @param {number} amount
         * @returns {string}
         */
        formatAmount(amount) {
            return this.$root.formatAmount(amount);
        }
    },
    
    template: `
        <div class="budget-item" @click="handleClick">
            <div class="budget-info">
                <div class="budget-category">
                    <span class="material-icons">category</span>
                    <span class="budget-name">{{ item.name }}</span>
                    <button class="budget-action" 
                            :title="$root.t('budgetEditTitle')" 
                            @click="handleEdit">
                        <span class="material-icons">settings</span>
                    </button>
                </div>
                <div class="budget-amounts">
                    <div class="amount-item consumed">
                        <span class="amount-label">{{ $root.t('consumedLabel') }}</span>
                        <span class="amount-value">
                            {{ formatAmount(item.consummed) }} {{ $root.t('currencySymbol') }}
                        </span>
                    </div>
                    <div class="amount-item budgeted">
                        <span class="amount-label">{{ $root.t('budgetedLabel') }}</span>
                        <span class="amount-value">
                            {{ formatAmount(item.budget) }} {{ $root.t('currencySymbol') }}
                        </span>
                    </div>
                </div>
            </div>
            <div class="budget-chart">
                <div class="progress-bar-container">
                    <div class="progress-chip" :class="progressStatus">
                        <span class="material-icons">{{ progressIcon }}</span>
                        <span>{{ item.progress }}%</span>
                    </div>
                    <div class="progress-bar-linear">
                        <div class="progress-bar-fill" 
                             :class="progressStatus"
                             :style="{ width: Math.min(item.progress, 100) + '%' }">
                        </div>
                    </div>
                    <div class="progress-status" :class="progressStatus">
                        <span class="material-icons">{{ progressIcon }}</span>
                        <span>{{ statusMessage }}</span>
                    </div>
                </div>
            </div>
        </div>
    `
};
