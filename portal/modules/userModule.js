/**
 * User Module
 * 
 * Manages user administration state and methods.
 * Provides functionality for listing, creating, updating, and deleting users.
 * 
 * @module userModule
 */

/**
 * Creates a user module mixin for Vue components
 * @param {Function} getApiService - Function that returns the API service instance
 * @returns {Object} Vue mixin with user methods and data
 */
export function createUserModule(getApiService) {
    return {
        data() {
            return {
                users: [],
                loadingUsers: false,
                userError: null,
                showUserModal: false,
                userToEdit: null,
                userFormData: {
                    username: '',
                    password: '',
                    email: '',
                    firstname: '',
                    lastname: '',
                    is_admin: 0
                }
            };
        },

        methods: {
            /**
             * Loads all users from the API
             * @returns {Promise<void>}
             */
            async loadUsers() {
                const apiService = getApiService();
                if (!apiService) {
                    console.error('API service not initialized');
                    return;
                }

                this.loadingUsers = true;
                this.userError = null;

                try {
                    this.users = await apiService.fetchUsers();
                    console.log('Users loaded:', this.users.length);
                } catch (error) {
                    console.error('Error loading users:', error);
                    this.userError = error.message;
                    this.error = this.t('errorLoadingUsers') || 'Erreur lors du chargement des utilisateurs';
                } finally {
                    this.loadingUsers = false;
                }
            },

            /**
             * Opens the user modal for creating a new user
             */
            openAddUserModal() {
                this.userToEdit = null;
                this.userFormData = {
                    username: '',
                    password: '',
                    email: '',
                    firstname: '',
                    lastname: '',
                    is_admin: 0
                };
                this.showUserModal = true;
            },

            /**
             * Opens the user modal for editing an existing user
             * @param {Object} user - User to edit
             */
            openEditUserModal(user) {
                this.userToEdit = user;
                this.userFormData = {
                    username: user.username,
                    password: '', // Password is not loaded for security
                    email: user.email,
                    firstname: user.firstname || '',
                    lastname: user.lastname || '',
                    is_admin: Number(user.is_admin) ? 1 : 0
                };
                this.showUserModal = true;
            },

            /**
             * Closes the user modal
             */
            closeUserModal() {
                this.showUserModal = false;
                this.userToEdit = null;
                this.userFormData = {
                    username: '',
                    password: '',
                    email: '',
                    firstname: '',
                    lastname: '',
                    is_admin: 0
                };
            },

            /**
             * Validates user form data
             * @returns {boolean} True if valid, false otherwise
             */
            validateUserForm() {
                if (!this.userFormData.username || this.userFormData.username.trim() === '') {
                    this.showToast(this.t('usernameRequired') || 'Le nom d\'utilisateur est requis');
                    return false;
                }

                if (!this.userFormData.email || this.userFormData.email.trim() === '') {
                    this.showToast(this.t('emailRequired') || 'L\'email est requis');
                    return false;
                }

                // Email validation
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(this.userFormData.email)) {
                    this.showToast(this.t('emailInvalid') || 'L\'email est invalide');
                    return false;
                }

                // Password required only for new users
                if (!this.userToEdit && (!this.userFormData.password || this.userFormData.password.trim() === '')) {
                    this.showToast(this.t('passwordRequired') || 'Le mot de passe est requis');
                    return false;
                }

                // Password strength check (minimum 6 characters)
                if (this.userFormData.password && this.userFormData.password.length < 6) {
                    this.showToast(this.t('passwordTooShort') || 'Le mot de passe doit contenir au moins 6 caractères');
                    return false;
                }

                return true;
            },

            /**
             * Submits the user form (create or update)
             * @returns {Promise<void>}
             */
            async submitUserForm() {
                if (!this.validateUserForm()) {
                    return;
                }

                const apiService = getApiService();
                if (!apiService) {
                    console.error('API service not initialized');
                    return;
                }

                try {
                    if (this.userToEdit) {
                        // Update existing user
                        await apiService.updateUser(
                            this.userToEdit.id,
                            this.userFormData.email,
                            this.userFormData.firstname,
                            this.userFormData.lastname,
                            this.userFormData.password,
                            null,
                            null,
                            this.userFormData.is_admin
                        );
                        this.showToast(this.t('userUpdated') || 'Utilisateur modifié avec succès');
                        await this.loadUsers(); // Reload user list
                    } else {
                        // Create new user
                        await apiService.createUser(
                            this.userFormData.username,
                            this.userFormData.password,
                            this.userFormData.email,
                            this.userFormData.firstname,
                            this.userFormData.lastname,
                            this.userFormData.is_admin
                        );
                        this.showToast(this.t('userCreated') || 'Utilisateur créé avec succès');
                        await this.loadUsers(); // Reload user list
                    }
                    this.closeUserModal();
                } catch (error) {
                    console.error('Error submitting user form:', error);
                    this.showToast(error.message || (this.t('errorSavingUser') || 'Erreur lors de l\'enregistrement'));
                }
            },

            /**
             * Checks if a user is locked after too many failed logins
             * @param {Object} user - User
             * @returns {boolean}
             */
            isLocked(user) {
                return !!user.locked_until && new Date(String(user.locked_until).replace(' ', 'T')) > new Date();
            },

            /**
             * Unlocks a user locked after too many failed logins
             * @param {Object} user - User
             * @returns {Promise<void>}
             */
            async unlockUser(user) {
                try {
                    await getApiService().unlockUser(user.id);
                    this.showToast(this.t('userUnlocked', { username: user.username }));
                    await this.loadUsers();
                } catch (error) {
                    this.showToast(error.message);
                }
            },

            /**
             * Deletes a user
             * @param {Object} user - User to delete
             * @returns {Promise<void>}
             */
            async deleteUser(user) {
                if (!confirm(this.t('confirmDeleteUser', { username: user.username }) || 
                    `Êtes-vous sûr de vouloir supprimer l'utilisateur ${user.username} ?`)) {
                    return;
                }

                const apiService = getApiService();
                if (!apiService) {
                    console.error('API service not initialized');
                    return;
                }

                try {
                    await apiService.deleteUser(user.id);
                    this.showToast(this.t('userDeleted') || 'Utilisateur supprimé avec succès');
                    await this.loadUsers(); // Reload user list
                } catch (error) {
                    console.error('Error deleting user:', error);
                    this.showToast(error.message || (this.t('errorDeletingUser') || 'Erreur lors de la suppression'));
                }
            }
        }
    };
}
