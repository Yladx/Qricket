import './bootstrap';
import Alpine from 'alpinejs';
import Swal from 'sweetalert2';
import { confirmLogout, confirmAccountDeletion, confirmSubscription } from './sweetalert-utils';

window.Alpine = Alpine;
window.Swal = Swal;

// Make functions available globally
window.confirmLogout = confirmLogout;
window.confirmAccountDeletion = confirmAccountDeletion;
window.confirmSubscription = confirmSubscription;

// Global SweetAlert utility functions
window.SwalUtils = {
    // Confirm action with custom options
    confirm: function(options) {
        const defaultOptions = {
            title: 'Are you sure?',
            text: 'This action cannot be undone.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, proceed!',
            cancelButtonText: 'Cancel',
            reverseButtons: true
        };
        
        return Swal.fire({...defaultOptions, ...options});
    },
    
    // Success message
    success: function(title, text) {
        return Swal.fire({
            title: title || 'Success!',
            text: text || 'Operation completed successfully.',
            icon: 'success',
            timer: 3000,
            timerProgressBar: true
        });
    },
    
    // Error message
    error: function(title, text) {
        return Swal.fire({
            title: title || 'Error!',
            text: text || 'Something went wrong.',
            icon: 'error'
        });
    },
    
    // Info message
    info: function(title, text) {
        return Swal.fire({
            title: title || 'Information',
            text: text || 'Here is some information.',
            icon: 'info'
        });
    }
};

// Test function to verify SweetAlert is working
window.testSweetAlert = function() {
    Swal.fire('SweetAlert is working!', 'The npm installation is successful.', 'success');
};

// Log to console to verify the script is loading
console.log('SweetAlert loaded:', typeof Swal);
console.log('SweetAlert functions available:', {
    confirmLogout: typeof window.confirmLogout,
    confirmAccountDeletion: typeof window.confirmAccountDeletion,
    confirmSubscription: typeof window.confirmSubscription
});

Alpine.start();
