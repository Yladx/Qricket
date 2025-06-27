<?php

namespace App\Http\Controllers;

use App\Mail\PaymentConfirmationMail;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Http;

class XenditWebhookController extends Controller
{
    /**
     * Handle Xendit webhook events
     */
    public function handle(Request $request)
    {
        $payload = $request->all();
        
        // Try multiple possible header names for the callback token
        $token = $request->header('x-callback-token') ?? 
                 $request->header('X-Callback-Token') ?? 
                 $request->header('x-callbacktoken') ?? 
                 $request->header('X-CallbackToken') ?? 
                 $request->header('callback-token') ?? 
                 $request->header('Callback-Token');
                 
        $eventType = $this->determineEventType($payload);

        // Debug logging to see what's being received
        Log::info('Webhook debug info', [
            'all_headers' => $request->headers->all(),
            'received_token' => $token,
            'expected_token' => config('services.xendit.callback_token'),
            'token_match' => $token === config('services.xendit.callback_token'),
            'token_length_received' => strlen($token ?? ''),
            'token_length_expected' => strlen(config('services.xendit.callback_token') ?? ''),
        ]);

        // Save webhook data to local storage for debugging
        $this->saveWebhookToStorage($payload, $token, $eventType);

        // Validate webhook token
        if ($token !== config('services.xendit.callback_token')) {
            Log::warning('Invalid webhook token received', [
                'received_token' => $token,
                'expected_token' => config('services.xendit.callback_token'),
                'event_type' => $eventType,
                'payload' => $payload
            ]);
            return response()->json(['error' => 'Invalid token'], 401);
        }

        Log::info('Xendit webhook received', [
            'event_type' => $eventType,
            'payload' => $payload
        ]);

        try {
            switch ($eventType) {
                case 'invoice.paid':
                    return $this->handleInvoicePaid($payload);
                case 'invoice.expired':
                    return $this->handleInvoiceExpired($payload);
                case 'invoice.cancelled':
                    return $this->handleInvoiceCancelled($payload);
                case 'payment.completed':
                case 'payment.succeeded':
                    return $this->handlePaymentCompleted($payload);
                case 'payment.failed':
                    return $this->handlePaymentFailed($payload);
                case 'payment.after_expiry':
                case 'payment.received_after_expiry':
                    return $this->handlePaymentAfterExpiry($payload);
                case 'disbursement.completed':
                    return $this->handleDisbursementCompleted($payload);
                case 'disbursement.failed':
                    return $this->handleDisbursementFailed($payload);
                case 'refund.completed':
                    return $this->handleRefundCompleted($payload);
                case 'refund.failed':
                    return $this->handleRefundFailed($payload);
                default:
                    // Fallback: If status is PAID but event type is unknown, treat as invoice.paid
                    if (($payload['status'] ?? '') === 'PAID' && $eventType === 'unknown') {
                        Log::info('Treating unknown event with PAID status as invoice.paid', [
                            'payload' => $payload
                        ]);
                        return $this->handleInvoicePaid($payload);
                    }
                    
                    // Ultimate fallback: If we have any payment-related data, try to process it
                    if (isset($payload['payment_id']) || isset($payload['paid_amount']) || isset($payload['paid_at'])) {
                        Log::info('Treating payload with payment data as invoice.paid (ultimate fallback)', [
                            'event_type' => $eventType,
                            'payload' => $payload
                        ]);
                        return $this->handleInvoicePaid($payload);
                    }
                    
                    Log::info('Unhandled webhook event type', [
                        'event_type' => $eventType,
                        'payload' => $payload
                    ]);
                    return response()->json(['status' => 'ignored']);
            }
        } catch (\Exception $e) {
            Log::error('Error processing webhook', [
                'event_type' => $eventType,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $payload
            ]);
            
            // Ultimate fallback: Try to process as invoice.paid if it looks like a payment
            if (($payload['status'] ?? '') === 'PAID') {
                Log::info('Attempting to process failed webhook as invoice.paid (error recovery)', [
                    'original_error' => $e->getMessage()
                ]);
                try {
                    return $this->handleInvoicePaid($payload);
                } catch (\Exception $fallbackError) {
                    Log::error('Fallback processing also failed', [
                        'fallback_error' => $fallbackError->getMessage()
                    ]);
                }
            }
            
            return response()->json(['error' => 'Internal server error'], 500);
        }
    }

    /**
     * Determine the event type from the webhook payload
     */
    private function determineEventType($payload)
    {
        // Check for explicit event type (this is the primary way Xendit sends events)
        if (isset($payload['event'])) {
            return $payload['event'];
        }

        // Fallback: Infer from status and other fields (for older webhook formats)
        $status = $payload['status'] ?? null;
        $type = $payload['type'] ?? null;

        Log::info('Determining event type from payload', [
            'status' => $status,
            'type' => $type,
            'has_payment_method' => isset($payload['payment_method']),
            'has_payer_email' => isset($payload['payer_email']),
            'has_paid_at' => isset($payload['paid_at'])
        ]);

        // If we have a status but no type, infer based on status and other fields
        if ($status && !$type) {
            switch (strtoupper($status)) {
                case 'PAID':
                    // Check if this looks like a payment webhook
                    if (isset($payload['payment_method']) || isset($payload['payer_email']) || isset($payload['paid_at'])) {
                        return 'invoice.paid';
                    }
                    break;
                case 'EXPIRED':
                    return 'invoice.expired';
                case 'CANCELLED':
                case 'CANCELED':
                    return 'invoice.cancelled';
                case 'FAILED':
                    return 'payment.failed';
                case 'COMPLETED':
                    return 'payment.completed';
            }
        }

        // Original logic for when type is present
        if ($type === 'INVOICE') {
            switch (strtoupper($status)) {
                case 'PAID':
                    return 'invoice.paid';
                case 'EXPIRED':
                    return 'invoice.expired';
                case 'CANCELLED':
                case 'CANCELED':
                    return 'invoice.cancelled';
            }
        }

        if ($type === 'PAYMENT') {
            switch (strtoupper($status)) {
                case 'COMPLETED':
                case 'SUCCEEDED':
                    return 'payment.completed';
                case 'FAILED':
                    return 'payment.failed';
            }
        }

        if ($type === 'DISBURSEMENT') {
            switch (strtoupper($status)) {
                case 'COMPLETED':
                    return 'disbursement.completed';
                case 'FAILED':
                    return 'disbursement.failed';
            }
        }

        if ($type === 'REFUND') {
            switch (strtoupper($status)) {
                case 'COMPLETED':
                    return 'refund.completed';
                case 'FAILED':
                    return 'refund.failed';
            }
        }

        Log::warning('Could not determine event type from payload', [
            'status' => $status,
            'type' => $type,
            'payload_keys' => array_keys($payload)
        ]);

        return 'unknown';
    }

    /**
     * Handle invoice.paid event
     */
    private function handleInvoicePaid($payload)
    {
        Log::info('Processing invoice.paid event', [
            'invoice_id' => $payload['id'] ?? 'unknown',
            'external_id' => $payload['external_id'] ?? 'unknown',
            'payment_id' => $payload['payment_id'] ?? 'unknown',
            'status' => $payload['status'] ?? 'unknown',
            'amount' => $payload['amount'] ?? 'unknown',
            'payment_method' => $payload['payment_method'] ?? 'unknown',
            'ewallet_type' => $payload['ewallet_type'] ?? 'unknown'
        ]);

        // Extract invoice ID from different possible locations
        $invoiceId = $payload['id'] ?? $payload['external_id'] ?? null;
        
        if (!$invoiceId) {
            Log::error('No invoice ID found in invoice paid payload', ['payload' => $payload]);
            return response()->json(['error' => 'No invoice ID found'], 400);
        }

        // Find or create subscription
        $subscription = Subscription::where('xendit_invoice_id', $invoiceId)->first();
        
        if (!$subscription) {
            $subscription = $this->createSubscriptionFromWebhook($payload);
        }

        if (!$subscription) {
            Log::error('Failed to create or find subscription for paid invoice', [
                'invoice_id' => $invoiceId,
                'payload' => $payload
            ]);
            
            // Instead of returning error, create a minimal subscription to prevent webhook failure
            $subscription = $this->createMinimalSubscription($payload);
            
            if (!$subscription) {
                return response()->json(['error' => 'Failed to create subscription'], 500);
            }
        }

        // Check if payment is already processed
        if ($subscription->payment_status === 'paid') {
            Log::info('Payment already processed for subscription', [
                'subscription_id' => $subscription->id,
                'xendit_invoice_id' => $invoiceId
            ]);
            return response()->json(['status' => 'already_processed']);
        }

        // Update subscription status
        try {
            $subscription->update([
                'status' => 'active',
                'payment_status' => 'paid',
                'xendit_payment_id' => $payload['payment_id'] ?? $payload['id'] ?? null,
            ]);

            Log::info('Subscription payment status updated successfully', [
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'payment_status' => 'paid',
                'xendit_payment_id' => $payload['payment_id'] ?? $payload['id'] ?? null,
                'paid_amount' => $payload['paid_amount'] ?? $payload['amount'] ?? null,
                'paid_at' => $payload['paid_at'] ?? null,
                'payment_method' => $payload['payment_method'] ?? 'unknown',
                'ewallet_type' => $payload['ewallet_type'] ?? 'unknown'
            ]);

            // Send payment confirmation email
            if ($subscription->user) {
                $this->sendPaymentConfirmationEmail($subscription);
            }

            return response()->json(['status' => 'success']);

        } catch (\Exception $e) {
            Log::error('Failed to update subscription payment status', [
                'subscription_id' => $subscription->id,
                'xendit_invoice_id' => $invoiceId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => 'Failed to update subscription'], 500);
        }
    }

    /**
     * Handle invoice.expired event
     */
    private function handleInvoiceExpired($payload)
    {
        $subscription = Subscription::where('xendit_invoice_id', $payload['id'])->first();
        
        if ($subscription) {
            $subscription->update([
                'status' => 'expired',
                'payment_status' => 'expired',
            ]);

            Log::info('Subscription marked as expired', [
                'subscription_id' => $subscription->id,
                'xendit_invoice_id' => $payload['id']
            ]);
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * Handle invoice.cancelled event
     */
    private function handleInvoiceCancelled($payload)
    {
        $subscription = Subscription::where('xendit_invoice_id', $payload['id'])->first();
        
        if ($subscription) {
            $subscription->update([
                'status' => 'cancelled',
                'payment_status' => 'cancelled',
            ]);

            Log::info('Subscription marked as cancelled', [
                'subscription_id' => $subscription->id,
                'xendit_invoice_id' => $payload['id']
            ]);
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * Handle payment.completed event
     */
    private function handlePaymentCompleted($payload)
    {
        Log::info('Payment completed event received', [
            'payment_id' => $payload['id'] ?? null,
            'amount' => $payload['amount'] ?? null,
            'currency' => $payload['currency'] ?? null
        ]);

        return response()->json(['status' => 'success']);
    }

    /**
     * Handle payment.failed event
     */
    private function handlePaymentFailed($payload)
    {
        Log::info('Payment failed event received', [
            'payment_id' => $payload['id'] ?? null,
            'failure_reason' => $payload['failure_reason'] ?? null
        ]);

        return response()->json(['status' => 'success']);
    }

    /**
     * Handle payment.after_expiry event
     */
    private function handlePaymentAfterExpiry($payload)
    {
        Log::info('Processing payment received after expiry', [
            'payment_id' => $payload['id'] ?? 'unknown',
            'external_id' => $payload['external_id'] ?? 'unknown',
            'status' => $payload['status'] ?? 'unknown',
            'amount' => $payload['amount'] ?? 'unknown',
            'paid_amount' => $payload['paid_amount'] ?? 'unknown'
        ]);

        // Extract invoice ID from different possible locations
        $invoiceId = $payload['id'] ?? $payload['external_id'] ?? null;
        
        if (!$invoiceId) {
            Log::error('No invoice ID found in payment after expiry payload', ['payload' => $payload]);
            return response()->json(['error' => 'No invoice ID found'], 400);
        }

        // Find existing subscription
        $subscription = Subscription::where('xendit_invoice_id', $invoiceId)->first();
        
        if (!$subscription) {
            // Create new subscription if none exists
            $subscription = $this->createSubscriptionFromWebhook($payload);
        }

        if (!$subscription) {
            Log::error('Failed to create or find subscription for payment after expiry', [
                'invoice_id' => $invoiceId,
                'payload' => $payload
            ]);
            return response()->json(['error' => 'Subscription not found'], 404);
        }

        // Update subscription status to active (payment was received)
        try {
            $subscription->update([
                'status' => 'active',
                'payment_status' => 'paid',
                'xendit_payment_id' => $payload['payment_id'] ?? $payload['id'] ?? null,
            ]);

            Log::info('Subscription activated after late payment', [
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'payment_status' => 'paid',
                'xendit_payment_id' => $payload['payment_id'] ?? $payload['id'] ?? null,
                'paid_amount' => $payload['paid_amount'] ?? $payload['amount'] ?? null,
                'paid_at' => $payload['paid_at'] ?? null
            ]);

            // Send payment confirmation email
            if ($subscription->user) {
                $this->sendPaymentConfirmationEmail($subscription);
            }

            return response()->json(['status' => 'success']);

        } catch (\Exception $e) {
            Log::error('Failed to update subscription for late payment', [
                'subscription_id' => $subscription->id,
                'xendit_invoice_id' => $invoiceId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => 'Failed to update subscription'], 500);
        }
    }

    /**
     * Handle disbursement.completed event
     */
    private function handleDisbursementCompleted($payload)
    {
        Log::info('Disbursement completed event received', [
            'disbursement_id' => $payload['id'] ?? null,
            'amount' => $payload['amount'] ?? null,
            'currency' => $payload['currency'] ?? null
        ]);

        return response()->json(['status' => 'success']);
    }

    /**
     * Handle disbursement.failed event
     */
    private function handleDisbursementFailed($payload)
    {
        Log::info('Disbursement failed event received', [
            'disbursement_id' => $payload['id'] ?? null,
            'failure_reason' => $payload['failure_reason'] ?? null
        ]);

        return response()->json(['status' => 'success']);
    }

    /**
     * Handle refund.completed event
     */
    private function handleRefundCompleted($payload)
    {
        Log::info('Refund completed event received', [
            'refund_id' => $payload['id'] ?? null,
            'amount' => $payload['amount'] ?? null,
            'currency' => $payload['currency'] ?? null
        ]);

        return response()->json(['status' => 'success']);
    }

    /**
     * Handle refund.failed event
     */
    private function handleRefundFailed($payload)
    {
        Log::info('Refund failed event received', [
            'refund_id' => $payload['id'] ?? null,
            'failure_reason' => $payload['failure_reason'] ?? null
        ]);

        return response()->json(['status' => 'success']);
    }

    /**
     * Verify payment status with Xendit API
     */
    private function verifyPaymentStatusWithXendit($invoiceId)
    {
        try {
            Log::info('Verifying payment status with Xendit API', ['invoice_id' => $invoiceId]);

            $response = Http::withBasicAuth(config('services.xendit.api_key'), '')
                ->timeout(30)
                ->get("https://api.xendit.co/v2/invoices/{$invoiceId}");

            if ($response->successful()) {
                $invoiceData = $response->json();
                $status = $invoiceData['status'] ?? 'unknown';
                
                Log::info('Payment status verified with Xendit', [
                    'invoice_id' => $invoiceId,
                    'status' => $status,
                    'amount' => $invoiceData['amount'] ?? null,
                    'currency' => $invoiceData['currency'] ?? null
                ]);
                
                return strtoupper($status);
            }

            Log::warning('Failed to verify payment status with Xendit', [
                'invoice_id' => $invoiceId,
                'response_status' => $response->status(),
                'response_body' => $response->body(),
                'response_headers' => $response->headers()
            ]);

            // If we can't verify with API, try to infer from webhook data
            return 'unknown';

        } catch (\Exception $e) {
            Log::error('Exception while verifying payment status with Xendit', [
                'invoice_id' => $invoiceId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 'unknown';
        }
    }

    /**
     * Create subscription from webhook payload
     */
    private function createSubscriptionFromWebhook($payload)
    {
        try {
            // Extract user information from different possible locations
            $userEmail = $payload['payer_email'] ?? $payload['customer']['email'] ?? null;
            
            // Extract customer name from various possible locations
            $firstName = $payload['customer']['given_names'] ?? $payload['customer']['first_name'] ?? '';
            $lastName = $payload['customer']['surname'] ?? $payload['customer']['last_name'] ?? '';
            
            // If no separate first/last name, try to split full name
            if (empty($firstName) && empty($lastName)) {
                $fullName = $payload['customer']['name'] ?? $payload['customer']['full_name'] ?? '';
                if (!empty($fullName)) {
                    $nameParts = explode(' ', trim($fullName), 2);
                    $firstName = $nameParts[0] ?? '';
                    $lastName = $nameParts[1] ?? '';
                }
            }

            // Ensure we have at least some name data
            if (empty($firstName) && empty($lastName)) {
                $firstName = 'Unknown';
                $lastName = 'User';
            }

            $fullName = trim($firstName . ' ' . $lastName);

            Log::info('Extracted user data from webhook', [
                'email' => $userEmail,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'full_name' => $fullName
            ]);

            // Find or create user
            $user = null;
            if ($userEmail) {
                $user = User::where('email', $userEmail)->first();
                
                if (!$user) {
                    // Create user if doesn't exist
                    $user = User::create([
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'email' => $userEmail,
                        'password' => bcrypt(str_random(12)), // Temporary password
                    ]);
                    
                    Log::info('User created from webhook', [
                        'user_id' => $user->id,
                        'email' => $userEmail,
                        'name' => $fullName
                    ]);
                } else {
                    // Update existing user's name if it was incomplete
                    if (empty($user->first_name) || empty($user->last_name)) {
                        $user->update([
                            'first_name' => $firstName,
                            'last_name' => $lastName,
                        ]);
                        
                        Log::info('Updated existing user name from webhook', [
                            'user_id' => $user->id,
                            'email' => $userEmail,
                            'name' => $fullName
                        ]);
                    }
                }
            } else {
                // Try to find user by external_id pattern or user_id
                $user = $this->findUserFromWebhookData($payload);
            }

            // Determine plan from amount or items array
            $amount = $payload['amount'] ?? $payload['paid_amount'] ?? null;
            $planId = $this->inferPlanFromAmount($amount);
            
            // If we have items array, try to get plan from there
            if (isset($payload['items']) && is_array($payload['items']) && !empty($payload['items'])) {
                $item = $payload['items'][0]; // Get first item
                $itemName = strtolower($item['name'] ?? '');
                
                if (strpos($itemName, 'basic') !== false) {
                    $planId = 'basic';
                } elseif (strpos($itemName, 'pro') !== false) {
                    $planId = 'pro';
                } elseif (strpos($itemName, 'enterprise') !== false) {
                    $planId = 'enterprise';
                }
                
                Log::info('Plan determined from items array', [
                    'item_name' => $item['name'] ?? 'unknown',
                    'inferred_plan' => $planId
                ]);
            }
            
            // Extract invoice ID from various locations
            $invoiceId = $payload['id'] ?? $payload['external_id'] ?? null;
            
            // Extract payment ID from various locations
            $paymentId = $payload['payment_id'] ?? $payload['id'] ?? null;

            // Extract currency
            $currency = $payload['currency'] ?? 'PHP';

            // Ensure we have valid data before creating subscription
            if (!$invoiceId) {
                Log::error('No invoice ID found in payload', ['payload' => $payload]);
                return null;
            }

            if (!$amount || $amount <= 0) {
                Log::error('Invalid amount in payload', ['amount' => $amount, 'payload' => $payload]);
                return null;
            }

            $subscription = Subscription::create([
                'user_id' => $user ? $user->id : null,
                'plan_id' => $planId,
                'status' => 'active',
                'xendit_invoice_id' => $invoiceId,
                'xendit_payment_id' => $paymentId,
                'amount' => $amount,
                'currency' => $currency,
                'start_date' => now(),
                'end_date' => now()->addMonth(),
                'payment_status' => 'paid',
            ]);

            Log::info('Subscription created from webhook', [
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'plan_id' => $subscription->plan_id,
                'invoice_id' => $invoiceId,
                'payment_id' => $paymentId,
                'amount' => $amount,
                'currency' => $currency,
                'payment_method' => $payload['payment_method'] ?? 'unknown',
                'ewallet_type' => $payload['ewallet_type'] ?? 'unknown'
            ]);

            return $subscription;

        } catch (\Exception $e) {
            Log::error('Failed to create subscription from webhook', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $payload
            ]);
            return null;
        }
    }

    /**
     * Infer plan from amount
     */
    private function inferPlanFromAmount($amount)
    {
        $planId = match((float)$amount) {
            199.0, 199 => 'basic',
            399.0, 399 => 'pro',
            999.0, 999 => 'enterprise',
            // Handle amounts in different currencies (e.g., IDR, PHP)
            50000.0, 50000 => 'basic', // IDR equivalent
            100000.0, 100000 => 'pro', // IDR equivalent
            250000.0, 250000 => 'enterprise', // IDR equivalent
            default => 'unknown'
        };

        Log::info('Plan inferred from amount', [
            'amount' => $amount,
            'inferred_plan' => $planId
        ]);

        return $planId;
    }

    /**
     * Send payment confirmation email
     */
    private function sendPaymentConfirmationEmail($subscription)
    {
        try {
            $plan = $this->getPlanDetails($subscription->plan_id);
            
            Mail::to($subscription->user->email)
                ->send(new PaymentConfirmationMail($subscription, $plan));
            
            Log::info('Payment confirmation email sent successfully', [
                'user_id' => $subscription->user_id,
                'user_email' => $subscription->user->email,
                'subscription_id' => $subscription->id
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send payment confirmation email', [
                'user_id' => $subscription->user_id,
                'user_email' => $subscription->user->email,
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Get plan details
     */
    private function getPlanDetails($planId)
    {
        return match($planId) {
            'basic' => [
                'id' => 'basic',
                'name' => 'Basic Plan',
                'price' => 199,
            ],
            'pro' => [
                'id' => 'pro',
                'name' => 'Pro Plan',
                'price' => 399,
            ],
            'enterprise' => [
                'id' => 'enterprise',
                'name' => 'Enterprise Plan',
                'price' => 999,
            ],
            default => [
                'id' => $planId,
                'name' => ucfirst($planId) . ' Plan',
                'price' => 0,
            ]
        };
    }

    /**
     * Save webhook data to local storage for debugging
     */
    private function saveWebhookToStorage($payload, $token, $eventType)
    {
        try {
            // Create webhooks directory if it doesn't exist
            $webhooksDir = storage_path('app/webhooks');
            if (!file_exists($webhooksDir)) {
                mkdir($webhooksDir, 0755, true);
            }

            // Create a filename with timestamp and event type
            $timestamp = now()->format('Y-m-d_H-i-s');
            $status = $payload['status'] ?? 'unknown';
            $invoiceId = $payload['id'] ?? 'unknown';
            $filename = "webhook_{$timestamp}_{$eventType}_{$status}_{$invoiceId}.json";
            $filepath = $webhooksDir . '/' . $filename;

            // Prepare data to save
            $dataToSave = [
                'webhook_data' => $payload,
                'event_type' => $eventType,
                'headers' => [
                    'x-callback-token' => $token,
                    'user-agent' => request()->header('User-Agent'),
                    'content-type' => request()->header('Content-Type'),
                ],
                'received_at' => now()->toISOString(),
                'file_info' => [
                    'filename' => $filename,
                    'saved_at' => now()->toISOString(),
                ]
            ];

            // Save to JSON file
            file_put_contents($filepath, json_encode($dataToSave, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            Log::info('Webhook data saved to local storage', [
                'filepath' => $filepath,
                'event_type' => $eventType,
                'status' => $status,
                'invoice_id' => $invoiceId
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to save webhook data to storage', [
                'error' => $e->getMessage(),
                'payload' => $payload
            ]);
        }
    }

    /**
     * Find user from webhook data
     */
    private function findUserFromWebhookData($payload)
    {
        try {
            // Try to find user by external_id if it contains user information
            $externalId = $payload['external_id'] ?? '';
            if (strpos($externalId, 'subscription-') === 0) {
                // Extract user ID from external_id if it follows a pattern
                $parts = explode('-', $externalId);
                if (count($parts) >= 2) {
                    $potentialUserId = $parts[1];
                    Log::info('Attempting to find user by external_id pattern', [
                        'external_id' => $externalId,
                        'potential_user_id' => $potentialUserId
                    ]);
                }
            }

            // Try to find user by user_id if present
            $xenditUserId = $payload['user_id'] ?? null;
            if ($xenditUserId) {
                Log::info('Attempting to find user by Xendit user_id', [
                    'xendit_user_id' => $xenditUserId
                ]);
                
                // You might want to store Xendit user_id in your users table
                // For now, we'll just log it
            }

            // Try to find user by looking for existing subscriptions with this invoice
            $invoiceId = $payload['id'] ?? $payload['external_id'] ?? null;
            if ($invoiceId) {
                $existingSubscription = Subscription::where('xendit_invoice_id', $invoiceId)->first();
                if ($existingSubscription && $existingSubscription->user) {
                    Log::info('Found existing user from subscription', [
                        'user_id' => $existingSubscription->user->id,
                        'invoice_id' => $invoiceId
                    ]);
                    return $existingSubscription->user;
                }
            }

            // If no user found, create a placeholder user
            Log::info('No existing user found, will create placeholder user');
            return null;

        } catch (\Exception $e) {
            Log::error('Error finding user from webhook data', [
                'error' => $e->getMessage(),
                'payload' => $payload
            ]);
            return null;
        }
    }

    /**
     * Create a minimal subscription to prevent webhook failure
     */
    private function createMinimalSubscription($payload)
    {
        try {
            // Extract user information from different possible locations
            $userEmail = $payload['payer_email'] ?? $payload['customer']['email'] ?? null;
            
            // Extract customer name from various possible locations
            $firstName = $payload['customer']['given_names'] ?? $payload['customer']['first_name'] ?? '';
            $lastName = $payload['customer']['surname'] ?? $payload['customer']['last_name'] ?? '';
            
            // If no separate first/last name, try to split full name
            if (empty($firstName) && empty($lastName)) {
                $fullName = $payload['customer']['name'] ?? $payload['customer']['full_name'] ?? '';
                if (!empty($fullName)) {
                    $nameParts = explode(' ', trim($fullName), 2);
                    $firstName = $nameParts[0] ?? '';
                    $lastName = $nameParts[1] ?? '';
                }
            }

            // Ensure we have at least some name data
            if (empty($firstName) && empty($lastName)) {
                $firstName = 'Unknown';
                $lastName = 'User';
            }

            $fullName = trim($firstName . ' ' . $lastName);

            Log::info('Extracted user data from webhook', [
                'email' => $userEmail,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'full_name' => $fullName
            ]);

            // Find or create user
            $user = null;
            if ($userEmail) {
                $user = User::where('email', $userEmail)->first();
                
                if (!$user) {
                    // Create user if doesn't exist
                    $user = User::create([
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'email' => $userEmail,
                        'password' => bcrypt(str_random(12)), // Temporary password
                    ]);
                    
                    Log::info('User created from webhook', [
                        'user_id' => $user->id,
                        'email' => $userEmail,
                        'name' => $fullName
                    ]);
                } else {
                    // Update existing user's name if it was incomplete
                    if (empty($user->first_name) || empty($user->last_name)) {
                        $user->update([
                            'first_name' => $firstName,
                            'last_name' => $lastName,
                        ]);
                        
                        Log::info('Updated existing user name from webhook', [
                            'user_id' => $user->id,
                            'email' => $userEmail,
                            'name' => $fullName
                        ]);
                    }
                }
            } else {
                // Try to find user by external_id pattern or user_id
                $user = $this->findUserFromWebhookData($payload);
            }

            // Determine plan from amount or items array
            $amount = $payload['amount'] ?? $payload['paid_amount'] ?? null;
            $planId = $this->inferPlanFromAmount($amount);
            
            // If we have items array, try to get plan from there
            if (isset($payload['items']) && is_array($payload['items']) && !empty($payload['items'])) {
                $item = $payload['items'][0]; // Get first item
                $itemName = strtolower($item['name'] ?? '');
                
                if (strpos($itemName, 'basic') !== false) {
                    $planId = 'basic';
                } elseif (strpos($itemName, 'pro') !== false) {
                    $planId = 'pro';
                } elseif (strpos($itemName, 'enterprise') !== false) {
                    $planId = 'enterprise';
                }
                
                Log::info('Plan determined from items array', [
                    'item_name' => $item['name'] ?? 'unknown',
                    'inferred_plan' => $planId
                ]);
            }
            
            // Extract invoice ID from various locations
            $invoiceId = $payload['id'] ?? $payload['external_id'] ?? null;
            
            // Extract payment ID from various locations
            $paymentId = $payload['payment_id'] ?? $payload['id'] ?? null;

            // Extract currency
            $currency = $payload['currency'] ?? 'PHP';

            $subscription = Subscription::create([
                'user_id' => $user ? $user->id : null,
                'plan_id' => $planId,
                'status' => 'active',
                'xendit_invoice_id' => $invoiceId,
                'xendit_payment_id' => $paymentId,
                'amount' => $amount,
                'currency' => $currency,
                'start_date' => now(),
                'end_date' => now()->addMonth(),
                'payment_status' => 'paid',
            ]);

            Log::info('Subscription created from webhook', [
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'plan_id' => $subscription->plan_id,
                'invoice_id' => $invoiceId,
                'payment_id' => $paymentId,
                'amount' => $amount,
                'currency' => $currency,
                'payment_method' => $payload['payment_method'] ?? 'unknown',
                'ewallet_type' => $payload['ewallet_type'] ?? 'unknown'
            ]);

            return $subscription;

        } catch (\Exception $e) {
            Log::error('Failed to create minimal subscription', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $payload
            ]);
            return null;
        }
    }
}
