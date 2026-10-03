/**
 * SqlEditor Component
 *
 * SQL editor with syntax highlighting (CodeMirror 5, self-hosted in
 * portal/vendor/codemirror), used for the queries of the insights.
 *
 * @component
 * @example
 * <sql-editor v-model="insight.sql" label-id="insight-sql-label"></sql-editor>
 */
export default {
    name: 'SqlEditor',

    props: {
        /** The query */
        modelValue: { type: String, default: '' },
        /** Id of the element labelling the editor (accessibility) */
        labelId: { type: String, default: '' },
        /** Id of the element describing the editor (help, errors) */
        describedBy: { type: String, default: '' }
    },

    emits: ['update:modelValue'],

    mounted() {
        // Without CodeMirror (not loaded), the textarea stays usable
        if (!window.CodeMirror) {
            return;
        }
        this.editor = window.CodeMirror.fromTextArea(this.$refs.textarea, {
            mode: 'text/x-mariadb',
            lineNumbers: true,
            lineWrapping: true,
            indentWithTabs: false,
            tabSize: 2,
            viewportMargin: Infinity,
            // Tab moves the focus out of the editor (keyboard users)
            extraKeys: { Tab: false, 'Shift-Tab': false }
        });
        const input = this.editor.getInputField();
        if (this.labelId) {
            input.setAttribute('aria-labelledby', this.labelId);
        }
        if (this.describedBy) {
            input.setAttribute('aria-describedby', this.describedBy);
        }
        input.setAttribute('aria-multiline', 'true');
        this.editor.on('change', () => {
            const value = this.editor.getValue();
            if (value !== this.modelValue) {
                this.$emit('update:modelValue', value);
            }
        });
    },

    watch: {
        modelValue(value) {
            if (this.editor && value !== this.editor.getValue()) {
                this.editor.setValue(value || '');
            }
        }
    },

    beforeUnmount() {
        if (this.editor) {
            this.editor.toTextArea();
        }
    },

    methods: {
        /** Focuses the editor */
        focus() {
            this.editor ? this.editor.focus() : this.$refs.textarea.focus();
        }
    },

    template: `
        <div class="sql-editor">
            <textarea ref="textarea" :value="modelValue" rows="6" spellcheck="false"
                      :aria-labelledby="labelId || null" :aria-describedby="describedBy || null"
                      @input="$emit('update:modelValue', $event.target.value)"></textarea>
        </div>
    `
};
