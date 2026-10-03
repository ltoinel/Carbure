/**
 * BudgetEditModal Component
 * 
 * Modal dialog for editing budget amounts.
 * Provides validation and API integration for updating budget values.
 * 
 * @component
 * @example
 * <budget-edit-modal 
 *   :show="isModalOpen"
 *   :budget="selectedBudget"
 *   @close="closeModal"
 *   @save="saveBudget">
 * </budget-edit-modal>
 */
export default {
    name: 'BudgetEditModal',
    
    props: {
        /**
         * Controls modal visibility
         */
        show: {
            type: Boolean,
            required: true
        },
        
        /**
         * Budget item to edit
         */
        budget: {
            type: Object,
            default: null
        }
    },
    
    emits: ['close', 'save'],
    
    data() {
        return {
            /**
             * Input value for new budget amount
             */
            newValue: ''
        };
    },
    
    watch: {
        /**
         * Initialize input value when budget prop changes
         */
        budget: {
            immediate: true,
            handler(newBudget) {
                if (newBudget) {
                    const current = parseFloat(newBudget.budget) || 0;
                    this.newValue = String(current);
                }
            }
        }
    },
    
    methods: {
        /**
         * Handles modal close action
         */
        handleClose() {
            this.$emit('close');
        },
        
        /**
         * Handles save action with validation
         * Emits save event with validated value
         */
        handleSave() {
            const value = parseFloat(this.newValue);
            if (Number.isNaN(value) || value < 0) {
                alert(this.$root.t('invalidBudgetValue'));
                return;
            }
            this.$emit('save', value);
        },
        
        /**
         * Handles click on overlay (background)
         * Closes modal if clicked outside content
         * @param {Event} event
         */
        handleOverlayClick(event) {
            if (event.target === event.currentTarget) {
                this.handleClose();
            }
        }
    },
    
    template: `
        <div v-if="show" class="modal-overlay" @click="handleOverlayClick">
            <div class="modal">
                <div class="modal-header">
                    <span class="material-icons">settings</span>
                    <h3>{{ $root.t('budgetEditTitle') }}</h3>
                    <button class="modal-close" @click="handleClose">
                        <span class="material-icons">close</span>
                    </button>
                </div>
                <div class="modal-body">
                    <label>{{ $root.t('budgetEditLabel') }}</label>
                    <input type="number" 
                           step="0.01" 
                           min="0" 
                           v-model="newValue" />
                </div>
                <div class="modal-footer">
                    <button class="btn-secondary" @click="handleClose">
                        <span class="material-icons">cancel</span>
                        {{ $root.t('cancel') }}
                    </button>
                    <button @click="handleSave">
                        <span class="material-icons">check_circle</span>
                        {{ $root.t('save') }}
                    </button>
                </div>
            </div>
        </div>
    `
};
