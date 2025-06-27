// SweetAlert Utility Functions
export function confirmLogout(formId) {
    Swal.fire({
        title: 'Are you sure?',
        text: "Do you want to logout from your account?",
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Yes, logout!',
        cancelButtonText: 'Cancel',
        reverseButtons: true
    }).then((result) => {
        if (result.isConfirmed) {
            // Show loading state
            Swal.fire({
                title: 'Logging out...',
                text: 'Please wait while we log you out.',
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            // Submit the form
            document.getElementById(formId).submit();
        }
    });
}

export function confirmAccountDeletion() {
    Swal.fire({
        title: 'Delete Account?',
        text: "This action cannot be undone. All your data will be permanently deleted!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'Yes, delete my account!',
        cancelButtonText: 'Cancel',
        reverseButtons: true,
        input: 'password',
        inputLabel: 'Enter your password to confirm',
        inputPlaceholder: 'Your password',
        inputValidator: (value) => {
            if (!value) {
                return 'You need to enter your password!';
            }
        },
        showLoaderOnConfirm: true,
        preConfirm: (password) => {
            // Set the password in the hidden form
            document.getElementById('delete-password').value = password;
            
            // Show loading state
            return new Promise((resolve) => {
                setTimeout(() => {
                    resolve();
                }, 1000);
            });
        },
        allowOutsideClick: () => !Swal.isLoading()
    }).then((result) => {
        if (result.isConfirmed) {
            // Show final confirmation
            Swal.fire({
                title: 'Final Confirmation',
                text: 'Are you absolutely sure you want to delete your account? This action is irreversible!',
                icon: 'error',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, I understand. Delete it!',
                cancelButtonText: 'No, keep my account',
                reverseButtons: true
            }).then((finalResult) => {
                if (finalResult.isConfirmed) {
                    // Show loading state
                    Swal.fire({
                        title: 'Deleting Account...',
                        text: 'Please wait while we delete your account.',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        showConfirmButton: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    
                    // Submit the form
                    document.getElementById('delete-account-form').submit();
                }
            });
        }
    });
}

export function confirmSubscription(planId, planName, planPrice) {
    Swal.fire({
        title: 'Confirm Subscription',
        html: `
            <div class="text-left">
                <p class="mb-4">You are about to subscribe to:</p>
                <div class="bg-gray-50 p-4 rounded-lg mb-4">
                    <h3 class="font-semibold text-lg text-gray-900">${planName}</h3>
                    <p class="text-2xl font-bold text-indigo-600">₱${planPrice.toLocaleString()}</p>
                    <p class="text-sm text-gray-500">per month</p>
                </div>
                <p class="text-sm text-gray-600">You will be redirected to the payment gateway to complete your subscription.</p>
            </div>
        `,
        icon: 'info',
        showCancelButton: true,
        confirmButtonColor: '#4f46e5',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'Proceed to Payment',
        cancelButtonText: 'Cancel',
        reverseButtons: true,
        width: '500px'
    }).then((result) => {
        if (result.isConfirmed) {
            // Show loading state
            Swal.fire({
                title: 'Processing...',
                text: 'Preparing your subscription...',
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            // Submit the form
            document.getElementById(`subscription-form-${planId}`).submit();
        }
    });
}

// Make functions available globally
window.confirmLogout = confirmLogout;
window.confirmAccountDeletion = confirmAccountDeletion;
window.confirmSubscription = confirmSubscription; 