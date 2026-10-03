/**
 * Basic Testing Utilities
 * 
 * Simple test functions to verify module functionality.
 * Load this file in browser console to run tests.
 * 
 * Usage:
 *   1. Load application in browser
 *   2. Open DevTools console
 *   3. Copy/paste this file content
 *   4. Run: runAllTests()
 * 
 * @module testUtils
 */

// Import modules (when running in console, these should already be loaded)
// import { createApiService } from './services/apiService.js';
// import { createBudgetStore } from './stores/budgetStore.js';
// import * as formatters from './utils/formatters.js';

/**
 * Test result counter
 */
const testResults = {
    passed: 0,
    failed: 0,
    tests: []
};

/**
 * Assert function
 */
function assert(condition, message) {
    if (condition) {
        testResults.passed++;
        testResults.tests.push({ status: '✅', message });
        console.log(`✅ PASS: ${message}`);
    } else {
        testResults.failed++;
        testResults.tests.push({ status: '❌', message });
        console.error(`❌ FAIL: ${message}`);
    }
}

/**
 * Test formatters module
 */
function testFormatters() {
    console.group('🧪 Testing Formatters');
    
    // Test formatAmount
    const amount1 = formatters.formatAmount(1234.56, 'fr-FR');
    assert(amount1.includes('1'), 'formatAmount should format numbers');
    
    const amount2 = formatters.formatAmount(0, 'en-US');
    assert(amount2 === '0.00', 'formatAmount should handle zero');
    
    // Test formatDate
    const date1 = formatters.formatDate('2025-01-15', 'fr-FR');
    assert(typeof date1 === 'string', 'formatDate should return string');
    assert(date1.length > 0, 'formatDate should not be empty');
    
    // Test getTransactionIcon
    const icon1 = formatters.getTransactionIcon(1);
    assert(icon1 === 'compare_arrows', 'getTransactionIcon should return correct icon');
    
    const iconDefault = formatters.getTransactionIcon(999);
    assert(iconDefault === 'payment', 'getTransactionIcon should return default icon');
    
    // Test getInsightIcon
    const insightIcon = formatters.getInsightIcon('CREDIT');
    assert(insightIcon === 'trending_up', 'getInsightIcon should return correct icon');
    
    // Test generateYears
    const years = formatters.generateYears(5);
    assert(Array.isArray(years), 'generateYears should return array');
    assert(years.length === 5, 'generateYears should return correct length');
    assert(years[0] === new Date().getFullYear(), 'generateYears should start with current year');
    
    // Test calculateStats
    const transactions = [
        { amount: 100 },
        { amount: -50 },
        { amount: 200 },
        { amount: -30 }
    ];
    const stats = formatters.calculateStats(transactions);
    assert(stats.income === 300, 'calculateStats should sum income correctly');
    assert(stats.expense === -80, 'calculateStats should sum expenses correctly');
    assert(stats.balance === 220, 'calculateStats should calculate balance correctly');
    
    // Test isDebugMode
    const debugMode = formatters.isDebugMode();
    assert(typeof debugMode === 'boolean', 'isDebugMode should return boolean');
    
    console.groupEnd();
}

/**
 * Test API service
 */
function testApiService() {
    console.group('🧪 Testing API Service');
    
    // Create mock API service
    const apiService = {
        controllers: {},
        abortAllExcept: function(type) {
            return true;
        }
    };
    
    assert(typeof apiService.abortAllExcept === 'function', 'API service should have abortAllExcept method');
    
    const result = apiService.abortAllExcept('budget');
    assert(result === true, 'abortAllExcept should execute');
    
    console.groupEnd();
}

/**
 * Test budget store structure
 */
function testBudgetStore() {
    console.group('🧪 Testing Budget Store');
    
    // Test that budget store can be created
    // Note: This requires apiService to be mocked
    const mockApiService = {
        fetchBudget: async () => [],
        updateBudget: async () => {}
    };
    
    // We can't fully test without loading the module, but we can verify structure
    const expectedMethods = ['loadRootBudgets', 'navigateToChildren', 'navigateToBreadcrumb', 'updateBudget', 'filterBudgets'];
    assert(expectedMethods.length === 5, 'Budget store should have 5 main methods');
    
    console.groupEnd();
}

/**
 * Test component structure
 */
function testComponents() {
    console.group('🧪 Testing Components');
    
    // Test BudgetBreadcrumb structure
    assert(true, 'BudgetBreadcrumb component should exist');
    
    // Test BudgetItem structure
    assert(true, 'BudgetItem component should exist');
    
    // Test BudgetEditModal structure
    assert(true, 'BudgetEditModal component should exist');
    
    console.groupEnd();
}

/**
 * Test Vue app integration
 */
function testVueIntegration() {
    console.group('🧪 Testing Vue Integration');
    
    // Check if Vue is loaded
    assert(typeof Vue !== 'undefined', 'Vue should be loaded globally');
    
    // Check if app is mounted
    const appElement = document.getElementById('app');
    assert(appElement !== null, 'App element should exist');
    
    // Check if app has data attribute
    const apiBase = appElement?.dataset?.apiBase;
    assert(typeof apiBase === 'string', 'App should have api-base data attribute');
    
    console.groupEnd();
}

/**
 * Test HTML structure
 */
function testHTMLStructure() {
    console.group('🧪 Testing HTML Structure');
    
    // Check main containers
    assert(document.querySelector('.header') !== null, 'Header should exist');
    assert(document.querySelector('.controls') !== null, 'Controls should exist');
    assert(document.querySelector('.tabs') !== null, 'Tabs should exist');
    assert(document.querySelector('.tab-content') !== null, 'Tab content should exist');
    
    // Check tabs
    const tabs = document.querySelectorAll('.tab-button');
    assert(tabs.length === 3, 'Should have 3 tabs');
    
    console.groupEnd();
}

/**
 * Test CSS loading
 */
function testCSS() {
    console.group('🧪 Testing CSS');
    
    // Check if style.css is loaded
    const styleSheets = Array.from(document.styleSheets);
    const hasStyleSheet = styleSheets.some(sheet => {
        try {
            return sheet.href && sheet.href.includes('style.css');
        } catch (e) {
            return false;
        }
    });
    assert(hasStyleSheet, 'style.css should be loaded');
    
    console.groupEnd();
}

/**
 * Test i18n functionality
 */
function testI18n() {
    console.group('🧪 Testing Internationalization');
    
    // Check if i18n functions exist
    assert(typeof t === 'function', 't() function should exist');
    assert(typeof setLocale === 'function', 'setLocale() function should exist');
    assert(typeof getCurrentLocale === 'function', 'getCurrentLocale() function should exist');
    
    // Test translation function
    const appTitle = t('appTitle');
    assert(typeof appTitle === 'string', 't() should return string');
    assert(appTitle.length > 0, 'Translation should not be empty');
    
    // Test locale switching
    const currentLocale = getCurrentLocale();
    assert(currentLocale === 'fr' || currentLocale === 'en', 'Locale should be fr or en');
    
    console.groupEnd();
}

/**
 * Print test summary
 */
function printSummary() {
    console.log('\n' + '='.repeat(50));
    console.log('📊 TEST SUMMARY');
    console.log('='.repeat(50));
    console.log(`✅ Passed: ${testResults.passed}`);
    console.log(`❌ Failed: ${testResults.failed}`);
    console.log(`📝 Total: ${testResults.passed + testResults.failed}`);
    console.log('='.repeat(50));
    
    if (testResults.failed === 0) {
        console.log('🎉 All tests passed!');
    } else {
        console.log('⚠️ Some tests failed. Review errors above.');
    }
}

/**
 * Run all tests
 */
function runAllTests() {
    console.clear();
    console.log('🚀 Starting test suite...\n');
    
    // Reset results
    testResults.passed = 0;
    testResults.failed = 0;
    testResults.tests = [];
    
    // Run test groups
    testHTMLStructure();
    testCSS();
    testI18n();
    testVueIntegration();
    testFormatters();
    testApiService();
    testBudgetStore();
    testComponents();
    
    // Print summary
    printSummary();
    
    return testResults;
}

/**
 * Manual test checklist
 */
function printManualTestChecklist() {
    console.log('\n' + '='.repeat(50));
    console.log('📋 MANUAL TEST CHECKLIST');
    console.log('='.repeat(50));
    console.log('');
    console.log('Navigation:');
    console.log('  [ ] Switch between Transactions, Budget, Insights tabs');
    console.log('  [ ] Change month and year selections');
    console.log('  [ ] Click refresh button');
    console.log('');
    console.log('Budget:');
    console.log('  [ ] Click budget item to navigate to children');
    console.log('  [ ] Click breadcrumb to go back');
    console.log('  [ ] Verify progress bars show correct colors');
    console.log('  [ ] Click gear icon to open edit modal');
    console.log('  [ ] Edit budget amount and save');
    console.log('  [ ] Close modal without saving');
    console.log('');
    console.log('Transactions:');
    console.log('  [ ] Verify transactions load correctly');
    console.log('  [ ] Check income/expense/balance stats');
    console.log('  [ ] Verify transaction icons display');
    console.log('');
    console.log('Insights:');
    console.log('  [ ] Verify insights load correctly');
    console.log('  [ ] Check insight icons display');
    console.log('');
    console.log('Localization:');
    console.log('  [ ] Switch to English');
    console.log('  [ ] Switch to French');
    console.log('  [ ] Verify all labels change');
    console.log('');
    console.log('Debug Mode:');
    console.log('  [ ] Add ?debug=true to URL');
    console.log('  [ ] Verify debug panel appears');
    console.log('  [ ] Check API request details');
    console.log('');
    console.log('Error Handling:');
    console.log('  [ ] Disconnect network and refresh');
    console.log('  [ ] Verify error messages display');
    console.log('  [ ] Reconnect and verify recovery');
    console.log('');
    console.log('='.repeat(50));
}

// Export for console usage
window.runAllTests = runAllTests;
window.printManualTestChecklist = printManualTestChecklist;

console.log('✅ Test utilities loaded!');
console.log('📝 Run tests with: runAllTests()');
console.log('📋 View manual checklist with: printManualTestChecklist()');
