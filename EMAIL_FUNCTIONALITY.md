# Email Functionality for Invoice System

This document describes the email functionality that has been added to send invoice and payment confirmation emails to users.

## Overview

The application now automatically sends emails to users in two scenarios:
1. **Invoice Email**: Sent when a subscription is created and an invoice is generated
2. **Payment Confirmation Email**: Sent when a payment is successfully processed

## Files Created/Modified

### Mail Classes
- `app/Mail/InvoiceMail.php` - Handles invoice email sending
- `app/Mail/PaymentConfirmationMail.php` - Handles payment confirmation email sending

### Email Templates
- `resources/views/emails/invoice.blade.php` - Invoice email template
- `resources/views/emails/payment-confirmation.blade.php` - Payment confirmation email template

### Controller Updates
- `app/Http/Controllers/SubscriptionController.php` - Added email sending functionality, payment status fixes, and JSON storage

### Console Commands
- `app/Console/Commands/SendInvoiceEmail.php` - Manual invoice email sending
- `app/Console/Commands/SendPaymentConfirmationEmail.php` - Manual payment confirmation email sending
- `app/Console/Commands/FixPendingSubscriptions.php` - Fix subscriptions stuck in pending status
- `app/Console/Commands/ViewInvoiceData.php` - View saved invoice and webhook JSON data

### Routes
- Added email preview routes in `routes/web.php` (development only)
- Added payment status check route for debugging

### Storage
- `storage/app/invoices/` - Directory for saved invoice JSON files
- `storage/app/webhooks/` - Directory for saved webhook JSON files

## Email Templates Features

### Invoice Email Template
- Professional design with company branding
- Displays subscription plan details and pricing
- Shows invoice information (ID, date, due date, status, amount)
- Includes payment button for pending payments
- Lists accepted payment methods
- Contains contact information and business hours

### Payment Confirmation Email Template
- Success-focused design with green color scheme
- Displays payment confirmation details
- Shows transaction information
- Lists subscription features based on plan
- Includes subscription period
- Contains dashboard access link
- Professional footer with contact information

## How It Works

### Automatic Email Sending

1. **Invoice Creation**:
   - When a user creates a subscription, an invoice is generated via Xendit
   - The system automatically sends an invoice email to the user
   - Email includes the payment link and all relevant invoice details
   - **Invoice JSON is saved to local storage for debugging**

2. **Payment Confirmation**:
   - When Xendit webhook confirms payment success
   - The system automatically sends a payment confirmation email
   - Email confirms the subscription is now active
   - **Webhook JSON is saved to local storage for debugging**

### Error Handling
- All email sending is wrapped in try-catch blocks
- Failed email sends are logged for debugging
- Email failures don't interrupt the main subscription flow

### Payment Status Fixes
- Enhanced webhook processing with better error handling
- Duplicate payment processing prevention
- Manual payment status checking capabilities
- Console commands to fix stuck subscriptions

### JSON Storage System
- **Invoice Storage**: All Xendit invoice responses are saved to `storage/app/invoices/`
- **Webhook Storage**: All webhook payloads are saved to `storage/app/webhooks/`
- **File Naming**: Files include timestamp, user info, and status for easy identification
- **Data Structure**: JSON files include metadata like user info, plan details, and timestamps

## Testing and Development

### Email Preview Routes (Development Only)
```
/email/preview/invoice
/email/preview/payment-confirmation
```
These routes allow you to preview email templates in the browser during development.

### Console Commands
```bash
# Send invoice email for a specific subscription
php artisan email:send-invoice {subscription_id}

# Send payment confirmation email for a specific subscription
php artisan email:send-payment-confirmation {subscription_id}

# Fix a specific subscription stuck in pending status
php artisan subscription:fix-pending --subscription-id={id}

# Fix all pending subscriptions
php artisan subscription:fix-pending --all

# View saved invoice and webhook data
php artisan invoice:view all
php artisan invoice:view invoices
php artisan invoice:view webhooks
php artisan invoice:view invoices --latest
php artisan invoice:view --file=invoice_2024-01-15_10-30-45_1_basic.json
```

### Payment Status Check Route
```
GET /subscription/{subscription_id}/check-payment
```
This route allows you to manually check and update payment status for a specific subscription.

## JSON Storage Structure

### Invoice Files (`storage/app/invoices/`)
```json
{
    "invoice_data": {
        "id": "xndit_invoice_id",
        "external_id": "subscription-xxx",
        "amount": 199,
        "status": "PENDING",
        "invoice_url": "https://...",
        // ... other Xendit invoice data
    },
    "user_info": {
        "id": 1,
        "name": "John Doe",
        "email": "john@example.com"
    },
    "plan_info": {
        "id": "basic",
        "name": "Basic Plan",
        "price": 199
    },
    "created_at": "2024-01-15T10:30:45.000000Z",
    "file_info": {
        "filename": "invoice_2024-01-15_10-30-45_1_basic.json",
        "saved_at": "2024-01-15T10:30:45.000000Z"
    }
}
```

### Webhook Files (`storage/app/webhooks/`)
```json
{
    "webhook_data": {
        "id": "xndit_invoice_id",
        "status": "PAID",
        "payment_id": "payment_xxx",
        // ... other webhook payload data
    },
    "headers": {
        "x-callback-token": "token_xxx",
        "user-agent": "Xendit-Webhook/1.0",
        "content-type": "application/json"
    },
    "received_at": "2024-01-15T10:35:20.000000Z",
    "file_info": {
        "filename": "webhook_2024-01-15_10-35-20_PAID_xndit_invoice_id.json",
        "saved_at": "2024-01-15T10:35:20.000000Z"
    }
}
```

## Configuration

### Mail Configuration
Ensure your mail configuration is set up in `.env`:
```env
MAIL_MAILER=smtp
MAIL_HOST=your-smtp-host
MAIL_PORT=587
MAIL_USERNAME=your-username
MAIL_PASSWORD=your-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@yourdomain.com
MAIL_FROM_NAME="Your Company Name"
```

### App Configuration
Update your app configuration in `.env`:
```env
APP_NAME="Your App Name"
APP_DOMAIN=yourdomain.com
```

### Xendit Configuration
Ensure Xendit is properly configured in `config/services.php`:
```php
'xendit' => [
    'api_key' => env('XENDIT_API_KEY'),
    'callback_token' => env('XENDIT_CALLBACK_TOKEN'),
],
```

## Email Content Customization

### Template Variables
Both email templates use the following variables:
- `$user` - User model with name and email
- `$subscription` - Subscription model with all details
- `$planDetails` - Array containing plan information
- `$invoiceUrl` - Payment link (for invoice emails)

### Styling
- Templates use inline CSS for maximum email client compatibility
- Responsive design that works on mobile and desktop
- Professional color scheme with brand colors
- Clear typography and spacing

## Security Considerations

- Email addresses are validated before sending
- Sensitive information is not included in emails
- Payment links are secure and time-limited
- Error logs don't contain sensitive user data
- Webhook token validation prevents unauthorized access
- JSON files are stored in secure `storage/app/` directory

## Monitoring and Logging

The system logs all email activities:
- Successful email sends
- Failed email sends with error details
- User and subscription information for tracking
- Webhook processing events
- Payment status updates
- JSON file storage events

Check the Laravel logs for email-related entries:
```bash
tail -f storage/logs/laravel.log | grep -i email
```

## Troubleshooting

### Common Issues

1. **Emails not sending**:
   - Check mail configuration in `.env`
   - Verify SMTP credentials
   - Check Laravel logs for error messages

2. **Email templates not rendering**:
   - Ensure Blade templates are in the correct location
   - Check for syntax errors in templates
   - Verify template variables are being passed correctly

3. **Payment confirmation emails not sending**:
   - Verify webhook is properly configured
   - Check if subscription status is being updated correctly
   - Ensure payment status is 'paid'
   - Use the fix command: `php artisan subscription:fix-pending --all`

4. **Subscriptions stuck in pending status**:
   - Check webhook logs for errors
   - Verify Xendit callback token is correct
   - Use the fix command to manually update status
   - Check Xendit dashboard for payment status

5. **JSON files not being saved**:
   - Check storage directory permissions
   - Verify `storage/app/` directory exists
   - Check Laravel logs for storage errors

### Testing Commands
```bash
# Test mail configuration
php artisan tinker
Mail::raw('Test email', function($message) { $message->to('test@example.com')->subject('Test'); });

# Check available commands
php artisan list | grep email
php artisan list | grep subscription
php artisan list | grep invoice

# Check webhook processing
tail -f storage/logs/laravel.log | grep -i webhook

# View saved JSON data
php artisan invoice:view all --latest
```

### Debugging Payment Issues
```bash
# Check all pending subscriptions
php artisan subscription:fix-pending --all

# Check specific subscription
php artisan subscription:fix-pending --subscription-id=1

# Check webhook logs
grep -i "Xendit webhook" storage/logs/laravel.log

# View latest invoice data
php artisan invoice:view invoices --latest

# View latest webhook data
php artisan invoice:view webhooks --latest
```

## File Management

### Viewing JSON Files
```bash
# List all invoice files
php artisan invoice:view invoices

# List all webhook files
php artisan invoice:view webhooks

# Show only latest files
php artisan invoice:view all --latest

# View specific file content
php artisan invoice:view --file=invoice_2024-01-15_10-30-45_1_basic.json
```

### Manual File Access
```bash
# Navigate to storage directories
cd storage/app/invoices/
cd storage/app/webhooks/

# List files
ls -la

# View file content
cat invoice_2024-01-15_10-30-45_1_basic.json | jq .
```

## Future Enhancements

Potential improvements for the email system:
- Email queue for better performance
- Email templates in multiple languages
- PDF invoice attachments
- Email tracking and analytics
- Customizable email templates via admin panel
- Email preferences for users
- Automated retry mechanism for failed webhooks
- Real-time payment status monitoring
- JSON file compression and archival
- Automated cleanup of old JSON files
- JSON data export functionality 