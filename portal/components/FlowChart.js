/**
 * FlowChart Component
 *
 * Money flow of a month as a Sankey diagram (inline SVG): the income sources on
 * the left feed the month's income in the middle, which goes to the expense
 * categories, the savings and what is left on the right. A table gives the
 * same figures (accessibility).
 *
 * @component
 * @example
 * <flow-chart :flow="budgetFlow" :t="t" :color-for="categoryColor" :format-amount="formatAmount"></flow-chart>
 */

/** Width of the drawing (it scales with the card) */
const WIDTH = 960;
/** Room for the labels on each side */
const LABEL = 190;
/** Width of a node */
const NODE = 14;
/** Space between two nodes of a column */
const GAP = 10;
/** Height given to the amounts (the drawing grows with the spaces between nodes) */
const FLOW_HEIGHT = 320;
/** Minimum room of a node, for its two lines of label */
const SLOT = 36;
/** Room above the middle node for its label */
const TOP = 48;
/** Expense categories shown before grouping the rest in "Others" */
const MAX_EXPENSES = 8;

/** Colors of the computed nodes */
const COLORS = { income: '#10b981', savings: '#3b82f6', balance: '#94a3b8', deficit: '#ef4444', others: '#a3a3a3', month: '#ef4860' };

export default {
    name: 'FlowChart',

    props: {
        /** Answer of GET /budget/flow */
        flow: { type: Object, required: true },
        /** Translation function of the app */
        t: { type: Function, required: true },
        /** CSS color of a category */
        colorFor: { type: Function, required: true },
        /** Amount formatter of the app */
        formatAmount: { type: Function, required: true }
    },

    data() {
        return { hover: null, showTable: false };
    },

    computed: {
        /** Nodes of the left column: income sources (and the deficit) */
        sources() {
            const nodes = this.flow.income.map(c => ({
                key: 'in-' + c.id,
                name: c.name || this.t('uncategorized'),
                amount: c.amount,
                color: c.color ? this.colorFor(c) : COLORS.income
            }));
            if (this.flow.balance < 0) {
                nodes.push({ key: 'deficit', name: this.t('flowDeficit'), amount: -this.flow.balance, color: COLORS.deficit });
            }
            return nodes;
        },

        /** Nodes of the right column: expenses (the smallest grouped), savings, what is left */
        targets() {
            const expenses = this.flow.expenses.map(c => ({
                key: 'out-' + c.id,
                name: c.name || this.t('uncategorized'),
                amount: c.amount,
                color: this.colorFor(c)
            }));
            const nodes = expenses.slice(0, MAX_EXPENSES);
            const rest = expenses.slice(MAX_EXPENSES);
            if (rest.length) {
                nodes.push({ key: 'others', name: this.t('flowOthers', { count: rest.length }), amount: rest.reduce((s, n) => s + n.amount, 0), color: COLORS.others });
            }
            if (this.flow.savings > 0) {
                nodes.push({ key: 'savings', name: this.t('trendsSavings'), amount: this.flow.savings, color: COLORS.savings });
            }
            if (this.flow.balance > 0) {
                nodes.push({ key: 'balance', name: this.t('flowBalance'), amount: this.flow.balance, color: COLORS.balance });
            }
            return nodes;
        },

        /** Total going through the middle node */
        total() {
            return Math.max(this.sources.reduce((s, n) => s + n.amount, 0), this.targets.reduce((s, n) => s + n.amount, 0));
        },

        /** Height of the drawing: the tallest column */
        height() {
            return this.layout.height;
        },

        /**
         * Geometry: nodes with their position and the links as SVG paths
         * @returns {{nodes: Array, links: Array, middle: Object}}
         */
        layout() {
            const scale = this.total > 0 ? FLOW_HEIGHT / this.total : 0;
            const xLeft = LABEL;
            const xMiddle = WIDTH / 2 - NODE / 2;
            const xRight = WIDTH - LABEL - NODE;
            // Each node takes at least SLOT pixels so that the labels never overlap
            const room = list => list.reduce((s, n) => s + Math.max(n.amount * scale, SLOT), 0) + GAP * Math.max(list.length - 1, 0);
            const h = TOP + Math.max(room(this.sources), room(this.targets), FLOW_HEIGHT) + 8;

            const column = (list, x) => {
                let y = TOP + (h - TOP - 8 - room(list)) / 2;
                return list.map(n => {
                    const height = Math.max(n.amount * scale, 2);
                    const slot = Math.max(height, SLOT);
                    // The bar is centered in its slot
                    const node = { ...n, x, y: y + (slot - height) / 2, height };
                    y += slot + GAP;
                    return node;
                });
            };
            const left = column(this.sources, xLeft);
            const right = column(this.targets, xRight);
            const middleHeight = this.total * scale;
            const middle = { key: 'month', name: this.t('flowIncome'), amount: this.total, color: COLORS.month, x: xMiddle, y: TOP + (h - TOP - 8 - middleHeight) / 2, height: middleHeight };

            const band = (x0, y0, x1, y1, size) => {
                const curve = (x1 - x0) / 2;
                return `M${x0},${y0} C${x0 + curve},${y0} ${x1 - curve},${y1} ${x1},${y1}`
                    + ` L${x1},${y1 + size} C${x1 - curve},${y1 + size} ${x0 + curve},${y0 + size} ${x0},${y0 + size} Z`;
            };

            const links = [];
            let inY = middle.y;
            for (const n of left) {
                const size = n.amount * scale;
                links.push({ key: n.key, node: n, d: band(n.x + NODE, n.y, middle.x, inY, size) });
                inY += size;
            }
            let outY = middle.y;
            for (const n of right) {
                const size = n.amount * scale;
                links.push({ key: n.key, node: n, d: band(middle.x + NODE, outY, n.x, n.y, size) });
                outY += size;
            }
            return { nodes: [...left, middle, ...right], left, right, middle, links, height: h };
        },

        /** Short description for screen readers */
        summary() {
            return this.t('flowSummary', {
                income: this.money(this.flow.totalIncome),
                expenses: this.money(this.flow.totalExpenses),
                savings: this.money(Math.max(this.flow.savings, 0)),
                balance: this.money(this.flow.balance)
            });
        }
    },

    methods: {
        /**
         * Amount with the currency
         * @param {number} value - Amount
         * @returns {string}
         */
        money(value) {
            return `${this.formatAmount(value)} ${this.t('currencySymbol')}`;
        },

        /**
         * Share of the month's income
         * @param {number} value - Amount
         * @returns {string}
         */
        percent(value) {
            return this.total > 0 ? `${Math.round(value / this.total * 100)} %` : '';
        }
    },

    template: `
        <div class="flow-chart">
            <svg :viewBox="'0 0 ' + 960 + ' ' + height" preserveAspectRatio="xMidYMid meet" role="img" :aria-label="summary"
                 @mouseleave="hover = null">
                <path v-for="link in layout.links" :key="link.key" :d="link.d" class="flow-link"
                      :class="{ dim: hover && hover !== link.key, active: hover === link.key }"
                      :style="{ fill: link.node.color }" @mouseenter="hover = link.key">
                    <title>{{ link.node.name }} : {{ money(link.node.amount) }} ({{ percent(link.node.amount) }})</title>
                </path>
                <g v-for="node in layout.nodes" :key="node.key" @mouseenter="hover = node.key === 'month' ? null : node.key">
                    <rect :x="node.x" :y="node.y" :width="14" :height="node.height" rx="3" :style="{ fill: node.color }">
                        <title>{{ node.name }} : {{ money(node.amount) }}</title>
                    </rect>
                </g>
                <g class="flow-labels">
                    <g v-for="node in layout.left" :key="'l-' + node.key">
                        <text :x="node.x - 10" :y="node.y + node.height / 2 - 2" text-anchor="end" class="flow-name">{{ node.name }}</text>
                        <text :x="node.x - 10" :y="node.y + node.height / 2 + 14" text-anchor="end" class="flow-amount">{{ money(node.amount) }}</text>
                    </g>
                    <g v-for="node in layout.right" :key="'r-' + node.key">
                        <text :x="node.x + 24" :y="node.y + node.height / 2 - 2" class="flow-name">{{ node.name }}</text>
                        <text :x="node.x + 24" :y="node.y + node.height / 2 + 14" class="flow-amount">{{ money(node.amount) }} · {{ percent(node.amount) }}</text>
                    </g>
                    <text :x="layout.middle.x + 7" :y="Math.min(layout.middle.y, 48) - 26" text-anchor="middle" class="flow-name">{{ layout.middle.name }}</text>
                    <text :x="layout.middle.x + 7" :y="Math.min(layout.middle.y, 48) - 10" text-anchor="middle" class="flow-amount">{{ money(flow.totalIncome) }}</text>
                </g>
            </svg>
            <button type="button" class="btn-secondary flow-table-toggle" @click="showTable = !showTable" :aria-expanded="showTable ? 'true' : 'false'">
                <span class="material-icons" aria-hidden="true">table_rows</span>
                {{ showTable ? t('flowHideTable') : t('flowShowTable') }}
            </button>
            <table v-if="showTable" class="flow-table">
                <caption class="visually-hidden">{{ summary }}</caption>
                <thead>
                    <tr><th scope="col">{{ t('flowFrom') }}</th><th scope="col">{{ t('flowTo') }}</th><th scope="col" class="num">{{ t('flowAmount') }}</th><th scope="col" class="num">%</th></tr>
                </thead>
                <tbody>
                    <tr v-for="n in sources" :key="'t-' + n.key"><td>{{ n.name }}</td><td>{{ t('flowIncome') }}</td><td class="num">{{ money(n.amount) }}</td><td class="num">{{ percent(n.amount) }}</td></tr>
                    <tr v-for="n in targets" :key="'t-' + n.key"><td>{{ t('flowIncome') }}</td><td>{{ n.name }}</td><td class="num">{{ money(n.amount) }}</td><td class="num">{{ percent(n.amount) }}</td></tr>
                </tbody>
            </table>
        </div>
    `
};
