# Carbure Portal - Modular Vue.js Application

A modern, modular banking portal application built with Vue.js 3, featuring hierarchical budget navigation, transaction tracking, and financial insights.

## 🎯 Features

- **Transaction Management**: View and track all banking transactions with filtering
- **Hierarchical Budget Navigation**: Navigate through nested budget categories with breadcrumb navigation
- **Budget Editing**: Modify budget amounts through a modern modal interface
- **Financial Insights**: View analytical insights about your spending patterns
- **Multi-language Support**: French and English localization
- **Responsive Design**: Mobile-first approach with adaptive layouts
- **Debug Mode**: Development tools accessible via query parameter

## 🏗️ Architecture

This application follows Vue.js best practices with a modular architecture:

```
portal/
├── components/          # Reusable Vue components
│   ├── BudgetBreadcrumb.js
│   ├── BudgetItem.js
│   └── BudgetEditModal.js
├── services/           # API communication layer
│   └── apiService.js
├── stores/             # State management
│   └── budgetStore.js
├── utils/              # Utility functions
│   └── formatters.js
├── app-refactored.js   # Main application entry point
├── i18n.js             # Internationalization
├── index.html          # Application template
└── style.css           # Styling
```

See [ARCHITECTURE.md](./ARCHITECTURE.md) for detailed documentation.

## 🚀 Getting Started

### Prerequisites

- Modern web browser with ES6 module support
- HTTP server (required for ES6 modules)
- Backend API (see API Requirements below)

### Installation

1. Clone the repository
2. Serve the `portal` directory via HTTP server:

```bash
# Using Python
python -m http.server 8000

# Using Node.js http-server
npx http-server -p 8000

# Using PHP
php -S localhost:8000
```

3. Open browser to `http://localhost:8000`

### Configuration

Set the API base URL in `index.html`:

```html
<div id="app" data-api-base="http://your-api-server:3002">
```

## 📖 Usage

### Basic Navigation

1. **Select Period**: Use month/year dropdowns to select time period
2. **Switch Tabs**: Click Transactions, Budget, or Insights tabs
3. **Refresh**: Click refresh button to reload current tab data

### Budget Management

1. **Navigate Hierarchy**: Click a budget item to view its children
2. **Breadcrumb Navigation**: Click breadcrumb items to go back
3. **Edit Budget**: Click gear icon on any budget item to modify amount
4. **Progress Visualization**: Color-coded progress bars show budget status
   - 🟢 Green: < 90% consumed
   - 🟠 Orange: 90-100% consumed
   - 🔴 Red: > 100% consumed (over budget)

### Debug Mode

Enable debug mode by adding query parameter:
```
http://localhost:8000?debug=true
```

This shows API request details and response previews.

## 🔌 API Requirements

The application expects the following REST API endpoints:

### Transactions
```
GET /transaction?month={month}&year={year}
Response: Array<{id, label, amount, date, rdate, type}>
```

### Budget
```
GET /budget?month={month}&year={year}[&category={categoryId}]
Response: Array<{id, name, budget, consummed, progress}>

PUT /budget/{id}?month={month}&year={year}
Body: {budget: number}
Response: Success status
```

### Insights
```
GET /budget/insights?month={month}&year={year}
Response: Array<{name, value}>
```

## 🧩 Component API

### BudgetBreadcrumb

**Props:**
- `stack` (Array): Navigation breadcrumb items

**Events:**
- `navigate(index)`: Emitted when clicking breadcrumb item

### BudgetItem

**Props:**
- `item` (Object): Budget data

**Events:**
- `click(item)`: Emitted when item is clicked
- `edit(item)`: Emitted when edit button is clicked

### BudgetEditModal

**Props:**
- `show` (Boolean): Modal visibility
- `budget` (Object): Budget item to edit

**Events:**
- `close()`: Emitted when closing modal
- `save(value)`: Emitted when saving new value

## 🎨 Customization

### Styling

Edit `style.css` to customize appearance. Key CSS variables:

```css
--primary-color: #2196F3;
--success-color: #4CAF50;
--warning-color: #FF9800;
--danger-color: #F44336;
```

### Localization

Add translations in `i18n.js`:

```javascript
translations: {
  en: {
    appTitle: 'Carbure',
    // ... more translations
  },
  fr: {
    appTitle: 'Carbure',
    // ... more translations
  }
}
```

### Progress Thresholds

Modify thresholds in `BudgetItem.js`:

```javascript
progressStatus() {
  const progress = parseFloat(this.item.progress) || 0;
  if (progress > 100) return 'over-budget';
  if (progress >= 90) return 'near-limit';  // Change this value
  return 'ok';
}
```

## 🧪 Testing

### Manual Testing Checklist

- [ ] Transactions load correctly
- [ ] Budget navigation works (forward and back)
- [ ] Budget editing saves successfully
- [ ] Progress bars show correct colors
- [ ] Modal opens/closes properly
- [ ] Insights display correctly
- [ ] Language switching works
- [ ] Month/year selection updates data
- [ ] Debug mode activates with query parameter
- [ ] Empty states show when no data
- [ ] Error messages display for failed requests

### Browser Testing

Tested on:
- Chrome 90+
- Firefox 88+
- Safari 14+
- Edge 90+

## 📝 Development

### Adding a New Component

1. Create file in `components/` directory
2. Export component with `export default`
3. Add JSDoc documentation
4. Import in `app-refactored.js`
5. Register in `components` object
6. Use in template

Example:
```javascript
// components/MyComponent.js
export default {
  name: 'MyComponent',
  props: {
    data: { type: Object, required: true }
  },
  template: `<div>{{ data.name }}</div>`
};
```

### Adding a New API Endpoint

1. Add method to `apiService.js`
2. Document with JSDoc
3. Handle errors appropriately
4. Add AbortController support

### Adding a New Store

1. Create file in `stores/` directory
2. Export factory function
3. Return state and methods
4. Initialize in `app-refactored.js` mounted()

## 🐛 Troubleshooting

### Modules not loading

**Error:** `Uncaught SyntaxError: Cannot use import statement outside a module`

**Solution:** Ensure script tag has `type="module"`:
```html
<script type="module" src="app-refactored.js"></script>
```

### CORS errors

**Error:** `Access to script at '...' from origin 'null' has been blocked by CORS`

**Solution:** Serve files via HTTP server, not `file://` protocol

### Components not rendering

**Error:** Components show as plain HTML

**Solution:** 
1. Check components are registered in app
2. Verify imports are correct
3. Check console for errors

### API calls failing

**Error:** Network errors or 404s

**Solution:**
1. Verify `data-api-base` attribute is correct
2. Check API server is running
3. Inspect Network tab in DevTools

## 📚 Documentation

- [ARCHITECTURE.md](./ARCHITECTURE.md) - Detailed architecture documentation
- [MIGRATION.md](./MIGRATION.md) - Migration guide from old version
- Inline JSDoc comments in all source files

## 🤝 Contributing

### Code Style

- Use JSDoc comments for all public APIs
- Follow Vue.js style guide
- Write descriptive commit messages
- Keep functions small and focused
- Use English for all documentation

### Pull Request Process

1. Fork the repository
2. Create feature branch
3. Add comprehensive documentation
4. Test thoroughly
5. Submit PR with description

## 📄 License

All rights reserved © 2025

## 📞 Support

For questions or issues:
- Check documentation files
- Review inline JSDoc comments
- Inspect browser console for errors
- Test with debug mode enabled

---

**Version:** 1.0.0  
**Last Updated:** 2025  
**Framework:** Vue.js 3  
**Architecture:** Modular ES6
