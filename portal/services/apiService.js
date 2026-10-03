/**
 * API Service Module
 * 
 * Centralizes all API communication logic for the Carbure application.
 * Provides methods for transactions, budgets, and insights with error handling.
 * 
 * @module apiService
 */

/**
 * Creates an API service instance
 * @param {string} baseUrl - Base URL for API endpoints
 * @param {string} authToken - JWT authentication token
 * @param {Function} onUnauthorized - Called when the API answers 401 (expired or invalid token)
 * @returns {Object} API service methods
 */
export function createApiService(baseUrl, authToken = null, onUnauthorized = null) {
    /**
     * fetch() that reports an expired session (HTTP 401)
     * @param {string} url - Request URL
     * @param {Object} options - fetch options
     * @returns {Promise<Response>}
     */
    async function authFetch(url, options) {
        const response = await window.fetch(url, options);
        if (response.status === 401 && authToken && onUnauthorized) {
            onUnauthorized();
        }
        return response;
    }

    /**
     * Active AbortControllers for cancellable requests
     */
    const controllers = {
        transactions: null,
        budget: null,
        insights: null
    };
    
    /**
     * Creates headers with authentication
     * @returns {Object} Headers object
     */
    function getHeaders() {
        const headers = {
            'Content-Type': 'application/json'
        };
        
        if (authToken) {
            headers['Authorization'] = `Bearer ${authToken}`;
        }
        
        return headers;
    }
    
    /**
     * Aborts all ongoing requests except the specified one
     * @param {string} except - Request type to keep active
     */
    function abortAllExcept(except) {
        for (const [key, controller] of Object.entries(controllers)) {
            if (key === except) continue;
            if (controller && typeof controller.abort === 'function') {
                try {
                    controller.abort();
                } catch (e) {
                    // Ignore abort errors
                }
                controllers[key] = null;
            }
        }
    }
    
    /**
     * Fetches transactions for a given month and year
     * @param {number} month - Month (1-12)
     * @param {number} year - Year
     * @returns {Promise<Array>} Array of transaction objects
     * @throws {Error} If request fails
     */
    async function fetchTransactions(month, year) {
        if (controllers.transactions) {
            controllers.transactions.abort();
        }
        
        controllers.transactions = new AbortController();
        
        const url = `${baseUrl}/transaction?month=${month}&year=${year}`;
        const response = await authFetch(url, { 
            signal: controllers.transactions.signal,
            headers: getHeaders()
        });
        
        if (!response.ok) {
            throw new Error(`Failed to fetch transactions (${response.status})`);
        }
        
        const data = await response.json();
        controllers.transactions = null;
        
        return Array.isArray(data) ? data : [];
    }
    
    /**
     * Sends a request and returns the decoded JSON, using the API error message on failure
     * @param {string} url - Request URL
     * @param {Object} options - fetch options
     * @param {string} errorLabel - Error message prefix
     * @returns {Promise<any>} Decoded JSON response
     * @throws {Error} If request fails
     */
    async function request(url, options, errorLabel) {
        const response = await authFetch(url, { ...options, headers: getHeaders() });
        const text = await response.text();

        if (!response.ok) {
            let message = `${errorLabel} (${response.status})`;
            try {
                message = JSON.parse(text).error || message;
            } catch (e) {
                // Keep default error message
            }
            throw new Error(message);
        }

        return text ? JSON.parse(text) : null;
    }

    /**
     * Fetches the transactions of a category (sub-categories included) for a month
     * @param {number} month - Month (1-12)
     * @param {number} year - Year
     * @param {number} category - Category ID
     * @returns {Promise<Array>} Array of transaction objects
     */
    async function fetchCategoryTransactions(month, year, category) {
        const url = `${baseUrl}/transaction?month=${month}&year=${year}&category=${encodeURIComponent(category)}`;
        const data = await request(url, {}, 'Failed to fetch transactions');
        return Array.isArray(data) ? data : [];
    }

    /**
     * Searches transactions by label
     * @param {string} query - Text to find in the label (2 characters minimum)
     * @returns {Promise<Array>} Array of transaction objects, most recent first
     * @throws {Error} If request fails
     */
    async function searchTransactions(query) {
        const url = `${baseUrl}/transaction/search?query=${encodeURIComponent(query)}`;
        const data = await request(url, {}, 'Failed to search transactions');
        return Array.isArray(data) ? data : [];
    }

    /**
     * Points (checks) or unpoints a transaction
     * @param {number} id - Transaction ID
     * @param {boolean} pointed - New pointed status
     * @returns {Promise<void>}
     * @throws {Error} If request fails
     */
    async function setTransactionPointed(id, pointed) {
        const url = `${baseUrl}/transaction/pointed`;
        await request(url, {
            method: 'PUT',
            body: JSON.stringify({ id, pointed })
        }, 'Failed to update transaction');
    }

    /**
     * Fetches the devices of the authenticated user
     * @returns {Promise<Array>} Array of device objects
     * @throws {Error} If request fails
     */
    async function fetchDevices() {
        const data = await request(`${baseUrl}/device`, {}, 'Failed to fetch devices');
        return Array.isArray(data) ? data : [];
    }

    /**
     * Version of the database schema and migrations to apply (administrators)
     * @returns {Promise<Object>} {version, pending}
     */
    async function fetchSchemaStatus() {
        return request(`${baseUrl}/system/schema`, {}, 'Failed to read the schema version');
    }

    /**
     * Applies the pending migrations (administrators)
     * @returns {Promise<Object>} {version, applied}
     */
    async function migrateSchema() {
        return request(`${baseUrl}/system/migrate`, { method: 'POST' }, 'Failed to update the database');
    }

    /**
     * Fetches the insights with their SQL (administrators)
     * @returns {Promise<Array>} Array of {id, name, color, sql}
     */
    async function fetchInsightDefinitions() {
        const data = await request(`${baseUrl}/insight`, {}, 'Failed to fetch the insights');
        return Array.isArray(data) ? data : [];
    }

    /**
     * Creates or modifies an insight (the query is checked by the server)
     * @param {Object} insight - {id (null for a new one), name, color, sql}
     * @returns {Promise<Object>} The insight with the amount of the current month
     */
    async function saveInsight(insight) {
        const { id, name, color, sql } = insight;
        return id
            ? request(`${baseUrl}/insight`, { method: 'PUT', body: JSON.stringify({ id, name, color, sql }) }, 'Failed to modify the insight')
            : request(`${baseUrl}/insight`, { method: 'POST', body: JSON.stringify({ name, color, sql }) }, 'Failed to create the insight');
    }

    /**
     * Checks an insight query (syntax, rules) and computes it for a month
     * @param {string} sql - The query
     * @param {number} month - Month
     * @param {number} year - Year
     * @returns {Promise<Object>} {valid, amount} or {valid: false, error}
     */
    async function checkInsight(sql, month, year) {
        return request(`${baseUrl}/insight/check`, { method: 'POST', body: JSON.stringify({ sql, month, year }) }, 'Failed to check the query');
    }

    /**
     * Deletes an insight
     * @param {number} id - Insight ID
     * @returns {Promise<void>}
     */
    async function deleteInsight(id) {
        await request(`${baseUrl}/insight?id=${encodeURIComponent(id)}`, { method: 'DELETE' }, 'Failed to delete the insight');
    }

    /**
     * State of the MCP server
     * @returns {Promise<Object>} {enabled}
     */
    async function fetchMcpSettings() {
        return request(`${baseUrl}/mcp/settings`, {}, 'Failed to read the MCP server state');
    }

    /**
     * Enables or disables the MCP server (administrators)
     * @param {boolean} enabled - New state
     * @returns {Promise<Object>} {enabled}
     */
    async function updateMcpSettings(enabled) {
        return request(`${baseUrl}/mcp/settings`, { method: 'PUT', body: JSON.stringify({ enabled }) }, 'Failed to change the MCP server state');
    }

    /**
     * Unlocks a user locked after too many failed logins (administrators)
     * @param {number} id - User ID
     * @returns {Promise<void>}
     */
    async function unlockUser(id) {
        await request(`${baseUrl}/user/unlock`, { method: 'POST', body: JSON.stringify({ id }) }, 'Failed to unlock the user');
    }

    /**
     * Fetches the API tokens of the authenticated user (without the tokens themselves)
     * @returns {Promise<Array>} Array of {id, name, token_hint, created_at, last_used_at}
     */
    async function fetchApiTokens() {
        const data = await request(`${baseUrl}/token`, {}, 'Failed to fetch the API tokens');
        return Array.isArray(data) ? data : [];
    }

    /**
     * Creates an API token (its value is returned only this time)
     * @param {string} name - Name to recognize it
     * @param {number} days - Lifetime in days (0: never expires)
     * @returns {Promise<Object>} {id, name, token, token_hint, expires_at}
     */
    async function createApiToken(name, days = 0) {
        return request(`${baseUrl}/token`, { method: 'POST', body: JSON.stringify({ name, days }) }, 'Failed to create the API token');
    }

    /**
     * Revokes an API token
     * @param {number} id - Token ID
     * @returns {Promise<void>}
     */
    async function deleteApiToken(id) {
        await request(`${baseUrl}/token?id=${encodeURIComponent(id)}`, { method: 'DELETE' }, 'Failed to revoke the API token');
    }

    /**
     * Money flow of a month (income sources, expenses, savings)
     * @param {number} month - Month (1-12)
     * @param {number} year - Year
     * @returns {Promise<Object>} {income, expenses, savings, totalIncome, totalExpenses, balance}
     */
    async function fetchBudgetFlow(month, year) {
        return request(`${baseUrl}/budget/flow?month=${encodeURIComponent(month)}&year=${encodeURIComponent(year)}`, {}, 'Failed to fetch the money flow');
    }

    /**
     * Fetches the monthly trends of the household
     * @param {number} months - Number of months
     * @param {number} offset - Months between the current month and the end of the period
     * @returns {Promise<Array>} Array of {month, debit, credit, offBudget, planned, savings}
     * @throws {Error} If request fails
     */
    async function fetchTrends(months = 12, offset = 0) {
        const data = await request(`${baseUrl}/budget/trends?months=${months}&offset=${offset}`, {}, 'Failed to fetch trends');
        return Array.isArray(data) ? data : [];
    }

    /**
     * Sets the category of a transaction (the transaction is checked too)
     * @param {number} id - Transaction ID
     * @param {number} category - Category ID
     * @returns {Promise<void>}
     */
    async function setTransactionCategory(id, category) {
        await request(`${baseUrl}/transaction/category`, {
            method: 'PUT',
            body: JSON.stringify({ id, category: Number(category) })
        }, 'Failed to update the category');
    }

    /**
     * Fetches the bank accounts of the household (administrators)
     * @returns {Promise<Array>} Array of {id, bankId, account_number, bank_name, user_id, username,
     *                           last_sync_at, last_sync_status, last_sync_message}
     */
    async function fetchBankAccounts() {
        const data = await request(`${baseUrl}/bank/accounts`, {}, 'Failed to fetch bank accounts');
        return Array.isArray(data) ? data : [];
    }

    /**
     * Follows a bank account
     * @param {string} account_number - Account identifier in woob
     * @param {string} bank_name - woob backend name
     * @returns {Promise<Object>} The account
     */
    async function createBankAccount(account_number, bank_name) {
        return request(`${baseUrl}/bank`, { method: 'POST', body: JSON.stringify({ account_number, bank_name }) }, 'Failed to add the account');
    }

    /**
     * Modifies a followed bank account
     * @param {number} id - Account ID
     * @param {string} account_number - Account identifier in woob
     * @param {string} bank_name - woob backend name
     * @returns {Promise<Object>} The account
     */
    async function updateBankAccount(id, account_number, bank_name) {
        return request(`${baseUrl}/bank`, { method: 'PUT', body: JSON.stringify({ id, account_number, bank_name }) }, 'Failed to modify the account');
    }

    /**
     * Stops following a bank account (its transactions are kept)
     * @param {number} id - Account ID
     * @returns {Promise<void>}
     */
    async function deleteBankAccount(id) {
        await request(`${baseUrl}/bank?id=${encodeURIComponent(id)}`, { method: 'DELETE' }, 'Failed to delete the account');
    }

    /**
     * Bank backends configured in woob
     * @returns {Promise<Array>} Array of {name, module}
     */
    async function fetchBankBackends() {
        const data = await request(`${baseUrl}/bank/backends`, {}, 'Failed to list the woob banks');
        return Array.isArray(data) ? data : [];
    }

    /**
     * Banks supported by woob
     * @returns {Promise<Array>} Array of {module, description}
     */
    async function fetchBankModules() {
        const data = await request(`${baseUrl}/bank/modules`, {}, 'Failed to list the banks supported by woob');
        return Array.isArray(data) ? data : [];
    }

    /**
     * Accounts available in the banks configured in woob (can take a while)
     * @returns {Promise<Array>} Array of {bankId, account_number, bank_name, label, balance, currency, followed}
     */
    async function discoverBankAccounts() {
        const data = await request(`${baseUrl}/bank/discover`, {}, 'Failed to query woob');
        return Array.isArray(data) ? data : [];
    }

    /**
     * Starts a bank synchronization and reports each progress message.
     * The API answers with Server-Sent Events: "data: <message>" blocks, and
     * ": heartbeat" comments while woob is working.
     * @param {Function} onMessage - Called with each progress message
     * @param {string|null} account - Synchronize only this account (bankId)
     * @returns {Promise<void>} Resolved when the synchronization ends
     * @throws {Error} If the request fails
     */
    async function syncBanks(onMessage, account = null) {
        const query = account ? `?account=${encodeURIComponent(account)}` : '';
        const response = await authFetch(`${baseUrl}/bank/sync${query}`, { headers: getHeaders() });

        if (!response.ok || !response.body) {
            let message = `Failed to start the synchronization (${response.status})`;
            try {
                message = JSON.parse(await response.text()).error || message;
            } catch (e) {
                // Keep default error message
            }
            throw new Error(message);
        }

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';

        for (;;) {
            const { value, done } = await reader.read();
            buffer += decoder.decode(value || new Uint8Array(), { stream: !done });

            // Events are separated by a blank line
            let end;
            while ((end = buffer.indexOf('\n\n')) >= 0) {
                const event = buffer.slice(0, end);
                buffer = buffer.slice(end + 2);
                const data = event.split('\n')
                    .filter(line => line.startsWith('data: '))
                    .map(line => line.slice(6))
                    .join('\n');
                if (data) {
                    onMessage(data);
                }
            }

            if (done) {
                return;
            }
        }
    }

    /**
     * Fetches all the categories
     * @returns {Promise<Array>} Array of categories
     */
    async function fetchCategories() {
        const data = await request(`${baseUrl}/category`, {}, 'Failed to fetch categories');
        return Array.isArray(data) ? data : [];
    }

    /**
     * Creates a category
     * @param {Object} category - {name, type, parent_category, icon, color}
     * @returns {Promise<Object>} The category
     */
    async function createCategory(category) {
        return request(`${baseUrl}/category`, { method: 'POST', body: JSON.stringify(category) }, 'Failed to create the category');
    }

    /**
     * Modifies a category
     * @param {Object} category - {id, name, type, parent_category, icon, color}
     * @returns {Promise<Object>} The category
     */
    async function updateCategory(category) {
        return request(`${baseUrl}/category`, { method: 'PUT', body: JSON.stringify(category) }, 'Failed to modify the category');
    }

    /**
     * Deletes a category (its transactions become uncategorized)
     * @param {number} id - Category ID
     * @returns {Promise<void>}
     */
    async function deleteCategory(id) {
        await request(`${baseUrl}/category?id=${encodeURIComponent(id)}`, { method: 'DELETE' }, 'Failed to delete the category');
    }

    /**
     * Fetches the automatic categorization rules
     * @returns {Promise<Array>} Array of {id, keyword, category, category_name}
     */
    async function fetchRules() {
        const data = await request(`${baseUrl}/category/keyword`, {}, 'Failed to fetch rules');
        return Array.isArray(data) ? data : [];
    }

    /**
     * Creates an automatic categorization rule
     * @param {string} keyword - Text to find in the labels
     * @param {number} category - Category to set
     * @returns {Promise<Object>} Created rule
     */
    async function createRule(keyword, category) {
        return request(`${baseUrl}/category/keyword`, {
            method: 'POST',
            body: JSON.stringify({ keyword, category: Number(category) })
        }, 'Failed to create rule');
    }

    /**
     * Deletes an automatic categorization rule
     * @param {number} id - Rule ID
     * @returns {Promise<void>}
     */
    async function deleteRule(id) {
        await request(`${baseUrl}/category/keyword?id=${encodeURIComponent(id)}`, { method: 'DELETE' }, 'Failed to delete rule');
    }

    /**
     * Applies the rules to the transactions without category
     * @returns {Promise<{updated: number}>}
     */
    async function applyRules() {
        return request(`${baseUrl}/category/keyword/apply`, { method: 'POST' }, 'Failed to apply rules');
    }

    /**
     * Deletes a device of the authenticated user
     * @param {number} id - Device ID
     * @returns {Promise<void>}
     * @throws {Error} If request fails
     */
    async function deleteDevice(id) {
        await request(`${baseUrl}/device?id=${encodeURIComponent(id)}`, { method: 'DELETE' }, 'Failed to delete device');
    }

    /**
     * Fetches budget data for a given month and year
     * @param {number} month - Month (1-12)
     * @param {number} year - Year
     * @param {number} [categoryId] - Optional category ID for children budgets
     * @returns {Promise<Array>} Array of budget objects
     * @throws {Error} If request fails
     */
    async function fetchBudget(month, year, categoryId = null) {
        if (controllers.budget) {
            controllers.budget.abort();
        }
        
        controllers.budget = new AbortController();
        
        let url = `${baseUrl}/budget?month=${month}&year=${year}`;
        if (categoryId !== null) {
            url += `&category=${categoryId}`;
        }
        
        const response = await authFetch(url, { 
            signal: controllers.budget.signal,
            headers: getHeaders()
        });
        
        const text = await response.text();
        
        if (!response.ok) {
            throw new Error(`Failed to fetch budget (${response.status}) - ${text.slice(0, 200)}`);
        }
        
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            throw new Error('Invalid JSON response for budget');
        }
        
        controllers.budget = null;
        
        return Array.isArray(data) ? data : [];
    }
    
    /**
     * Updates a budget item
     * @param {number} budgetId - Budget item ID
     * @param {number} month - Month (1-12)
     * @param {number} year - Year
     * @param {number} newBudget - New budget amount
     * @returns {Promise<void>}
     * @throws {Error} If request fails
     */
    async function updateBudget(budgetId, month, year, newBudget) {
        const url = `${baseUrl}/budget/${budgetId}?month=${month}&year=${year}`;
        const response = await authFetch(url, {
            method: 'PUT',
            headers: getHeaders(),
            body: JSON.stringify({ budget: newBudget })
        });
        
        if (!response.ok) {
            const text = await response.text();
            throw new Error(`Failed to update budget (${response.status}) - ${text.slice(0, 200)}`);
        }
    }
    
    /**
     * Fetches insights data for a given month and year
     * @param {number} month - Month (1-12)
     * @param {number} year - Year
     * @returns {Promise<Array>} Array of insight objects
     * @throws {Error} If request fails
     */
    async function fetchInsights(month, year) {
        if (controllers.insights) {
            controllers.insights.abort();
        }
        
        controllers.insights = new AbortController();
        
        const url = `${baseUrl}/budget/insights?month=${month}&year=${year}`;
        const response = await authFetch(url, { 
            signal: controllers.insights.signal,
            headers: getHeaders()
        });
        
        const text = await response.text();
        
        if (!response.ok) {
            throw new Error(`Failed to fetch insights (${response.status}) - ${text.slice(0, 200)}`);
        }
        
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            throw new Error('Invalid JSON response for insights');
        }
        
        controllers.insights = null;
        
        return Array.isArray(data) ? data : [];
    }
    
    /**
     * Fetches all users
     * @returns {Promise<Array>} Array of user objects
     * @throws {Error} If request fails
     */
    async function fetchUsers() {
        const url = `${baseUrl}/user`;
        const response = await authFetch(url, {
            headers: getHeaders()
        });
        
        if (!response.ok) {
            throw new Error(`Failed to fetch users (${response.status})`);
        }
        
        const data = await response.json();
        return Array.isArray(data) ? data : [];
    }

    /**
     * Fetches the authenticated user profile
     * @returns {Promise<Object>} User object
     * @throws {Error} If request fails
     */
    async function fetchMe() {
        const url = `${baseUrl}/user/me`;
        const response = await authFetch(url, {
            headers: getHeaders()
        });
        
        if (!response.ok) {
            throw new Error(`Failed to fetch profile (${response.status})`);
        }
        
        return await response.json();
    }

    /**
     * Creates a new user
     * @param {string} username - Username
     * @param {string} password - Password
     * @param {string} email - Email
     * @param {string} firstname - First name (optional)
     * @param {string} lastname - Last name (optional)
     * @returns {Promise<Object>} Created user object
     * @throws {Error} If request fails
     */
    async function createUser(username, password, email, firstname, lastname, isAdmin = false) {
        const url = `${baseUrl}/user`;
        const response = await authFetch(url, {
            method: 'POST',
            headers: getHeaders(),
            body: JSON.stringify({ username, password, email, firstname, lastname, is_admin: !!isAdmin })
        });
        
        if (!response.ok) {
            const text = await response.text();
            let errorMessage = `Failed to create user (${response.status})`;
            try {
                const errorData = JSON.parse(text);
                if (errorData.error) {
                    errorMessage = errorData.error;
                }
            } catch (e) {
                // Keep default error message
            }
            throw new Error(errorMessage);
        }
        
        return await response.json();
    }

    /**
     * Deletes a user by id
     * @param {number} userId - User ID to delete
     * @returns {Promise<void>}
     * @throws {Error} If request fails
     */
    async function deleteUser(userId) {
        const url = `${baseUrl}/user?id=${userId}`;
        const response = await authFetch(url, {
            method: 'DELETE',
            headers: getHeaders()
        });
        
        if (!response.ok) {
            const text = await response.text();
            let errorMessage = `Failed to delete user (${response.status})`;
            try {
                const errorData = JSON.parse(text);
                if (errorData.error) {
                    errorMessage = errorData.error;
                }
            } catch (e) {
                // Keep default error message
            }
            throw new Error(errorMessage);
        }
        
        return await response.json();
    }

    /**
     * Updates an existing user
     * @param {number} userId - User ID to update
     * @param {string} email - Email
     * @param {string} firstname - First name (optional)
     * @param {string} lastname - Last name (optional)
     * @param {string} password - New password (optional)
     * @param {string} language - Interface language (optional, 'fr' or 'en')
     * @param {number|string} alertThreshold - Large expense alert threshold (optional, '' disables)
     * @returns {Promise<Object>} Updated user object
     * @throws {Error} If request fails
     */
    async function updateUser(userId, email, firstname, lastname, password, language, alertThreshold, isAdmin) {
        const url = `${baseUrl}/user?id=${userId}`;
        const body = { email, firstname, lastname };

        if (isAdmin !== undefined && isAdmin !== null) {
            body.is_admin = !!isAdmin;
        }

        if (language) {
            body.language = language;
        }

        if (alertThreshold !== undefined && alertThreshold !== null) {
            body.alertThreshold = String(alertThreshold);
        }
        
        // Only include password if provided
        if (password && password.trim() !== '') {
            body.password = password;
        }
        
        const response = await authFetch(url, {
            method: 'PUT',
            headers: getHeaders(),
            body: JSON.stringify(body)
        });
        
        if (!response.ok) {
            const text = await response.text();
            let errorMessage = `Failed to update user (${response.status})`;
            try {
                const errorData = JSON.parse(text);
                if (errorData.error) {
                    errorMessage = errorData.error;
                }
            } catch (e) {
                // Keep default error message
            }
            throw new Error(errorMessage);
        }
        
        return await response.json();
    }
    
    return {
        abortAllExcept,
        fetchTransactions,
        fetchBudget,
        updateBudget,
        fetchInsights,
        fetchUsers,
        fetchMe,
        fetchDevices,
        fetchApiTokens,
        fetchBudgetFlow,
        unlockUser,
        fetchMcpSettings,
        updateMcpSettings,
        fetchSchemaStatus,
        migrateSchema,
        fetchInsightDefinitions,
        saveInsight,
        deleteInsight,
        checkInsight,
        createApiToken,
        deleteApiToken,
        fetchBankAccounts,
        createBankAccount,
        updateBankAccount,
        deleteBankAccount,
        discoverBankAccounts,
        fetchBankBackends,
        fetchBankModules,
        syncBanks,
        fetchCategories,
        createCategory,
        updateCategory,
        deleteCategory,
        setTransactionCategory,
        fetchRules,
        createRule,
        deleteRule,
        applyRules,
        fetchTrends,
        deleteDevice,
        searchTransactions,
        fetchCategoryTransactions,
        setTransactionPointed,
        createUser,
        updateUser,
        deleteUser
    };
}
