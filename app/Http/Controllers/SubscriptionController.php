<?php

namespace App\Http\Controllers;

use App\Mail\InvoiceMail;
use App\Mail\PaymentConfirmationMail;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Models\User;

class SubscriptionController extends Controller
{
    public function index()
    {
        $plans = [
            [
                'id' => 'basic',
                'name' => 'Basic Plan',
                'price' => 199,
                'features' => [
                    'Basic features',
                    'Email support',
                    '1 user',
                ],
            ],
            [
                'id' => 'pro',
                'name' => 'Pro Plan',
                'price' => 399,
                'features' => [
                    'All Basic features',
                    'Priority support',
                    '5 users',
                    'Advanced features',
                ],
            ],
            [
                'id' => 'enterprise',
                'name' => 'Enterprise Plan',
                'price' => 999,
                'features' => [
                    'All Pro features',
                    '24/7 support',
                    'Unlimited users',
                    'Custom features',
                ],
            ],
        ];

        return view('subscription.index', compact('plans'));
    }

    public function create(Request $request)
    {
        $request->validate([
            'plan_id' => 'required|in:basic,pro,enterprise',
        ]);

        try {
            $plan = $this->getPlanDetails($request->plan_id);
            
            Log::info('Creating invoice for subscription', [
                'user_id' => $request->user()->id,
                'user_email' => $request->user()->email,
                'user_name' => $request->user()->full_name,
                'plan_id' => $plan['id'],
                'plan_price' => $plan['price']
            ]);
            
            // Create Xendit invoice
            $invoice = $this->createXenditInvoice($plan, $request->user());

            // Send invoice email to user (no subscription record yet)
            try {
                Mail::to($request->user()->email)
                    ->send(new InvoiceMail(null, $invoice['invoice_url'], $plan));
                
                Log::info('Invoice email sent successfully', [
                    'user_id' => $request->user()->id,
                    'user_email' => $request->user()->email,
                    'invoice_id' => $invoice['id']
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to send invoice email', [
                    'user_id' => $request->user()->id,
                    'user_email' => $request->user()->email,
                    'invoice_id' => $invoice['id'],
                    'error' => $e->getMessage()
                ]);
            }

            return redirect($invoice['invoice_url']);
            
        } catch (\Exception $e) {
            Log::error('Failed to create invoice for subscription', [
                'user_id' => $request->user()->id,
                'user_email' => $request->user()->email,
                'plan_id' => $request->plan_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return back()->withErrors(['error' => 'Failed to create payment invoice: ' . $e->getMessage()]);
        }
    }

    public function webhook(Request $request)
    {
        $payload = $request->all();
        $token = $request->header('x-callback-token');
        $signature = $request->header('x-xendit-signature');

        // Save webhook data to local storage for debugging
        $this->saveWebhookToStorage($payload, $token);

        // Validate webhook token
        if ($token !== config('services.xendit.callback_token')) {
            Log::warning('Invalid webhook token received', [
                'received_token' => $token,
                'expected_token' => config('services.xendit.callback_token'),
                'payload' => $payload
            ]);
            return response()->json(['error' => 'Invalid token'], 401);
        }

        // Verify webhook signature if available
        if ($signature && !$this->verifyWebhookSignature($request, $signature)) {
            Log::warning('Invalid webhook signature received', [
                'received_signature' => $signature,
                'payload' => $payload
            ]);
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        Log::info('Xendit webhook received', $payload);

        // Determine event type from payload
        $eventType = $this->determineEventType($payload);
        Log::info('Event type determined', ['event_type' => $eventType]);

        try {
            switch ($eventType) {
                case 'invoice.paid':
                case 'payment.succeeded':
                case 'payment.completed':
                    return $this->handlePaymentSuccess($payload);
                case 'invoice.expired':
                    return $this->handleInvoiceExpired($payload);
                case 'invoice.cancelled':
                    return $this->handleInvoiceCancelled($payload);
                case 'payment.failed':
                case 'payment.declined':
                    return $this->handlePaymentFailed($payload);
                case 'invoice.voided':
                    return $this->handleInvoiceVoided($payload);
                case 'payment.pending':
                    return $this->handlePaymentPending($payload);
                default:
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
            return response()->json(['error' => 'Internal server error'], 500);
        }
    }

    /**
     * Verify webhook signature from Xendit
     */
    private function verifyWebhookSignature(Request $request, $signature)
    {
        try {
            $payload = $request->getContent();
            $secretKey = config('services.xendit.webhook_secret');
            
            if (!$secretKey) {
                Log::warning('No webhook secret configured, skipping signature verification');
                return true; // Skip verification if no secret configured
            }

            $expectedSignature = hash_hmac('sha256', $payload, $secretKey);
            
            return hash_equals($expectedSignature, $signature);
        } catch (\Exception $e) {
            Log::error('Error verifying webhook signature', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Determine the event type from the webhook payload
     */
    private function determineEventType($payload)
    {
        // Check for explicit event type (new Xendit format)
        if (isset($payload['event'])) {
            return $payload['event'];
        }

        // Check for event type in data object
        if (isset($payload['data']['event'])) {
            return $payload['data']['event'];
        }

        // Handle actual Xendit payload format (based on real webhook data)
        $status = $payload['status'] ?? $payload['data']['status'] ?? null;
        
        // For Xendit invoices, status directly indicates the event
        if ($status) {
            switch (strtoupper($status)) {
                case 'PAID':
                case 'SUCCEEDED':
                case 'COMPLETED':
                    return 'invoice.paid';
                case 'EXPIRED':
                    return 'invoice.expired';
                case 'CANCELLED':
                case 'CANCELED':
                    return 'invoice.cancelled';
                case 'VOIDED':
                    return 'invoice.voided';
                case 'PENDING':
                    return 'payment.pending';
                case 'FAILED':
                case 'DECLINED':
                    return 'payment.failed';
            }
        }

        // Fallback: Check for type field (older format)
        $type = $payload['type'] ?? $payload['data']['type'] ?? null;

        if ($type === 'INVOICE') {
            switch (strtoupper($status)) {
                case 'PAID':
                case 'SUCCEEDED':
                    return 'invoice.paid';
                case 'EXPIRED':
                    return 'invoice.expired';
                case 'CANCELLED':
                case 'CANCELED':
                    return 'invoice.cancelled';
                case 'VOIDED':
                    return 'invoice.voided';
                case 'PENDING':
                    return 'payment.pending';
            }
        }

        if ($type === 'PAYMENT') {
            switch (strtoupper($status)) {
                case 'COMPLETED':
                case 'SUCCEEDED':
                case 'PAID':
                    return 'payment.succeeded';
                case 'FAILED':
                case 'DECLINED':
                    return 'payment.failed';
                case 'PENDING':
                    return 'payment.pending';
            }
        }

        // Additional checks for different payload structures
        if (isset($payload['payment_status'])) {
            switch (strtoupper($payload['payment_status'])) {
                case 'PAID':
                case 'SUCCEEDED':
                case 'COMPLETED':
                    return 'payment.succeeded';
                case 'FAILED':
                case 'DECLINED':
                    return 'payment.failed';
                case 'PENDING':
                    return 'payment.pending';
            }
        }

        return 'unknown';
    }

    /**
     * Handle successful payment events
     */
    private function handlePaymentSuccess($payload)
    {
        // Extract invoice ID from different possible locations
        $invoiceId = $payload['id'] ?? $payload['data']['id'] ?? $payload['invoice_id'] ?? null;
        
        if (!$invoiceId) {
            Log::error('No invoice ID found in payment success payload', ['payload' => $payload]);
            return response()->json(['error' => 'No invoice ID found'], 400);
        }

        Log::info('Processing payment success for invoice', ['invoice_id' => $invoiceId]);

        // Verify payment status with Xendit API
        $verifiedStatus = $this->verifyPaymentStatusWithXendit($invoiceId);
        
        if ($verifiedStatus !== 'PAID' && $verifiedStatus !== 'SUCCEEDED' && $verifiedStatus !== 'COMPLETED') {
            Log::warning('Payment status verification failed', [
                'invoice_id' => $invoiceId,
                'webhook_status' => $payload['status'] ?? 'unknown',
                'verified_status' => $verifiedStatus
            ]);
            return response()->json(['error' => 'Payment verification failed'], 400);
        }

        // Find or create subscription
        $subscription = Subscription::where('xendit_invoice_id', $invoiceId)->first();
        
        if (!$subscription) {
            $subscription = $this->createSubscriptionFromWebhook($payload);
        }

        if (!$subscription) {
            Log::error('Failed to create or find subscription for payment', [
                'invoice_id' => $invoiceId,
                'payload' => $payload
            ]);
            return response()->json(['error' => 'Subscription not found'], 404);
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
                'xendit_payment_id' => $payload['payment_id'] ?? $payload['data']['payment_id'] ?? $payload['payment']['id'] ?? null,
            ]);

            Log::info('Subscription payment status updated successfully', [
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'payment_status' => 'paid',
                'xendit_payment_id' => $payload['payment_id'] ?? $payload['data']['payment_id'] ?? $payload['payment']['id'] ?? null,
                'xendit_user_id' => $payload['user_id'] ?? null,
                'payment_method' => $payload['payment_method'] ?? null,
                'payment_channel' => $payload['payment_channel'] ?? null
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
     * Handle invoice expired events
     */
    private function handleInvoiceExpired($payload)
    {
        $invoiceId = $payload['id'] ?? $payload['data']['id'] ?? null;
        
        if ($invoiceId) {
            $subscription = Subscription::where('xendit_invoice_id', $invoiceId)->first();
            
            if ($subscription) {
                $subscription->update([
                    'status' => 'expired',
                    'payment_status' => 'expired',
                ]);

                Log::info('Subscription marked as expired', [
                    'subscription_id' => $subscription->id,
                    'xendit_invoice_id' => $invoiceId
                ]);
            }
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * Handle invoice cancelled events
     */
    private function handleInvoiceCancelled($payload)
    {
        $invoiceId = $payload['id'] ?? $payload['data']['id'] ?? null;
        
        if ($invoiceId) {
            $subscription = Subscription::where('xendit_invoice_id', $invoiceId)->first();
            
            if ($subscription) {
                $subscription->update([
                    'status' => 'cancelled',
                    'payment_status' => 'cancelled',
                ]);

                Log::info('Subscription marked as cancelled', [
                    'subscription_id' => $subscription->id,
                    'xendit_invoice_id' => $invoiceId
                ]);
            }
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * Handle payment failed events
     */
    private function handlePaymentFailed($payload)
    {
        $invoiceId = $payload['id'] ?? $payload['data']['id'] ?? null;
        
        if ($invoiceId) {
            $subscription = Subscription::where('xendit_invoice_id', $invoiceId)->first();
            
            if ($subscription) {
                $subscription->update([
                    'status' => 'failed',
                    'payment_status' => 'failed',
                ]);

                Log::info('Subscription marked as failed', [
                    'subscription_id' => $subscription->id,
                    'xendit_invoice_id' => $invoiceId
                ]);
            }
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * Handle invoice voided events
     */
    private function handleInvoiceVoided($payload)
    {
        $invoiceId = $payload['id'] ?? $payload['data']['id'] ?? null;
        
        if ($invoiceId) {
            $subscription = Subscription::where('xendit_invoice_id', $invoiceId)->first();
            
            if ($subscription) {
                $subscription->update([
                    'status' => 'voided',
                    'payment_status' => 'voided',
                ]);

                Log::info('Subscription marked as voided', [
                    'subscription_id' => $subscription->id,
                    'xendit_invoice_id' => $invoiceId
                ]);
            }
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * Handle payment pending events
     */
    private function handlePaymentPending($payload)
    {
        $invoiceId = $payload['id'] ?? $payload['data']['id'] ?? null;
        
        if ($invoiceId) {
            $subscription = Subscription::where('xendit_invoice_id', $invoiceId)->first();
            
            if ($subscription) {
                $subscription->update([
                    'status' => 'pending',
                    'payment_status' => 'pending',
                ]);

                Log::info('Subscription marked as pending', [
                    'subscription_id' => $subscription->id,
                    'xendit_invoice_id' => $invoiceId
                ]);
            }
        }

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
            $customerData = $payload['customer'] ?? $payload['data']['customer'] ?? [];
            $userEmail = $customerData['email'] ?? $payload['email'] ?? null;
            
            // For Xendit payloads, we might need to extract user info differently
            // Check if we have user_id in the payload
            $xenditUserId = $payload['user_id'] ?? null;
            
            // Extract customer name from various possible locations
            $firstName = $customerData['given_names'] ?? $customerData['first_name'] ?? $customerData['firstname'] ?? '';
            $lastName = $customerData['surname'] ?? $customerData['last_name'] ?? $customerData['lastname'] ?? '';
            
            // If no separate first/last name, try to split full name
            if (empty($firstName) && empty($lastName)) {
                $fullName = $customerData['name'] ?? $customerData['full_name'] ?? '';
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
                'xendit_user_id' => $xenditUserId,
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
            }

            // Validate and update user data if user exists
            if ($user) {
                $user = $this->validateAndUpdateUserData($user, $customerData);
            }

            // Determine plan from amount or items array
            $amount = $payload['amount'] ?? $payload['data']['amount'] ?? $payload['payment']['amount'] ?? null;
            $planId = $this->inferPlanFromAmount($amount);
            
            // If we have items array, try to extract plan from there
            if (isset($payload['items']) && is_array($payload['items']) && !empty($payload['items'])) {
                $item = $payload['items'][0]; // Take the first item
                $itemName = $item['name'] ?? '';
                $itemPrice = $item['price'] ?? null;
                
                // Try to infer plan from item name
                if (stripos($itemName, 'basic') !== false) {
                    $planId = 'basic';
                } elseif (stripos($itemName, 'pro') !== false) {
                    $planId = 'pro';
                } elseif (stripos($itemName, 'enterprise') !== false) {
                    $planId = 'enterprise';
                }
                
                // Use item price if available
                if ($itemPrice) {
                    $amount = $itemPrice;
                    $planId = $this->inferPlanFromAmount($amount);
                }
            }
            
            // Extract invoice ID from various locations
            $invoiceId = $payload['id'] ?? $payload['data']['id'] ?? $payload['invoice_id'] ?? null;
            
            // Extract payment ID from various locations
            $paymentId = $payload['payment_id'] ?? $payload['data']['payment_id'] ?? $payload['payment']['id'] ?? null;

            // Extract currency
            $currency = $payload['currency'] ?? $payload['data']['currency'] ?? $payload['payment']['currency'] ?? 'PHP';

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
                'xendit_user_id' => $xenditUserId
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
     * Validate and update user data from webhook
     */
    private function validateAndUpdateUserData($user, $customerData)
    {
        try {
            $updates = [];
            
            // Extract name data
            $firstName = $customerData['given_names'] ?? $customerData['first_name'] ?? $customerData['firstname'] ?? '';
            $lastName = $customerData['surname'] ?? $customerData['last_name'] ?? $customerData['lastname'] ?? '';
            
            // If no separate first/last name, try to split full name
            if (empty($firstName) && empty($lastName)) {
                $fullName = $customerData['name'] ?? $customerData['full_name'] ?? '';
                if (!empty($fullName)) {
                    $nameParts = explode(' ', trim($fullName), 2);
                    $firstName = $nameParts[0] ?? '';
                    $lastName = $nameParts[1] ?? '';
                }
            }

            // Update first name if we have new data and current is empty or different
            if (!empty($firstName) && (empty($user->first_name) || $user->first_name !== $firstName)) {
                $updates['first_name'] = $firstName;
            }

            // Update last name if we have new data and current is empty or different
            if (!empty($lastName) && (empty($user->last_name) || $user->last_name !== $lastName)) {
                $updates['last_name'] = $lastName;
            }

            // Update email if provided and different
            $email = $customerData['email'] ?? null;
            if ($email && $user->email !== $email) {
                // Check if email is already taken by another user
                $existingUser = User::where('email', $email)->where('id', '!=', $user->id)->first();
                if (!$existingUser) {
                    $updates['email'] = $email;
                } else {
                    Log::warning('Email already taken by another user', [
                        'current_user_id' => $user->id,
                        'existing_user_id' => $existingUser->id,
                        'email' => $email
                    ]);
                }
            }

            // Apply updates if any
            if (!empty($updates)) {
                $user->update($updates);
                
                Log::info('User data updated from webhook', [
                    'user_id' => $user->id,
                    'updates' => $updates
                ]);
            }

            return $user;

        } catch (\Exception $e) {
            Log::error('Failed to validate and update user data', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'customer_data' => $customerData
            ]);
            return $user;
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
     * Save webhook data to local storage for debugging
     */
    private function saveWebhookToStorage($payload, $token)
    {
        try {
            // Create webhooks directory if it doesn't exist
            $webhooksDir = storage_path('app/webhooks');
            if (!file_exists($webhooksDir)) {
                mkdir($webhooksDir, 0755, true);
            }

            // Create a filename with timestamp
            $timestamp = now()->format('Y-m-d_H-i-s');
            $status = $payload['status'] ?? 'unknown';
            $invoiceId = $payload['id'] ?? 'unknown';
            $filename = "webhook_{$timestamp}_{$status}_{$invoiceId}.json";
            $filepath = $webhooksDir . '/' . $filename;

            // Prepare data to save
            $dataToSave = [
                'webhook_data' => $payload,
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
            default => throw new \InvalidArgumentException('Invalid plan'),
        };
    }

    private function createXenditInvoice($plan, $user)
    {
        try {
            $response = Http::withBasicAuth(config('services.xendit.api_key'), '')
                ->post('https://api.xendit.co/v2/invoices', [
                    'external_id' => 'subscription-' . uniqid(),
                    'amount' => $plan['price'],
                    'description' => "Subscription to {$plan['name']}",
                    'invoice_duration' => 86400, // 24 hours
                    'customer' => [
                        'given_names' => $user->full_name,
                        'email' => $user->email,
                    ],
                    'success_redirect_url' => route('subscription.success'),
                    'failure_redirect_url' => route('subscription.failure'),
                    'currency' => 'PHP',
                    'items' => [
                        [
                            'name' => $plan['name'],
                            'quantity' => 1,
                            'price' => $plan['price'],
                            'category' => 'Subscription',
                        ],
                    ],
                ]);

            if (!$response->successful()) {
                Log::error('Xendit invoice creation failed', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                    'plan' => $plan,
                    'user' => $user->email,
                    'api_key' => substr(config('services.xendit.api_key'), 0, 5) . '...' // Log only first 5 chars for security
                ]);
                throw new \Exception('Failed to create payment invoice: ' . ($response->json()['message'] ?? 'Unknown error'));
            }

            $invoiceData = $response->json();
            
            // Save invoice JSON to local storage
            $this->saveInvoiceToStorage($invoiceData, $user, $plan);

            return $invoiceData;
        } catch (\Exception $e) {
            Log::error('Exception in createXenditInvoice', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Save invoice JSON data to local storage for debugging
     */
    private function saveInvoiceToStorage($invoiceData, $user, $plan)
    {
        try {
            // Create invoices directory if it doesn't exist
            $invoicesDir = storage_path('app/invoices');
            if (!file_exists($invoicesDir)) {
                mkdir($invoicesDir, 0755, true);
            }

            // Create a filename with timestamp and user info
            $timestamp = now()->format('Y-m-d_H-i-s');
            $filename = "invoice_{$timestamp}_{$user->id}_{$plan['id']}.json";
            $filepath = $invoicesDir . '/' . $filename;

            // Prepare data to save (exclude sensitive information)
            $dataToSave = [
                'invoice_data' => $invoiceData,
                'user_info' => [
                    'id' => $user->id,
                    'name' => $user->full_name,
                    'email' => $user->email,
                ],
                'plan_info' => $plan,
                'created_at' => now()->toISOString(),
                'file_info' => [
                    'filename' => $filename,
                    'saved_at' => now()->toISOString(),
                ]
            ];

            // Save to JSON file
            file_put_contents($filepath, json_encode($dataToSave, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            Log::info('Invoice JSON saved to local storage', [
                'filepath' => $filepath,
                'invoice_id' => $invoiceData['id'] ?? null,
                'user_id' => $user->id,
                'plan_id' => $plan['id']
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to save invoice JSON to storage', [
                'error' => $e->getMessage(),
                'invoice_id' => $invoiceData['id'] ?? null,
                'user_id' => $user->id
            ]);
        }
    }

    public function success()
    {
        return view('subscription.success');
    }

    public function failure()
    {
        return view('subscription.failure');
    }

    /**
     * Manually check and update payment status for a subscription
     * This can be used to fix subscriptions stuck in pending status
     */
    public function checkPaymentStatus(Request $request, $subscriptionId)
    {
        $subscription = Subscription::with('user')->findOrFail($subscriptionId);
        
        if ($subscription->payment_status === 'paid') {
            return response()->json([
                'message' => 'Subscription is already paid',
                'subscription' => $subscription
            ]);
        }

        // Check with Xendit API for payment status
        try {
            $response = Http::withBasicAuth(config('services.xendit.api_key'), '')
                ->get("https://api.xendit.co/v2/invoices/{$subscription->xendit_invoice_id}");

            if ($response->successful()) {
                $invoiceData = $response->json();
                
                if ($invoiceData['status'] === 'PAID') {
                    // Update subscription status
                    $subscription->update([
                        'status' => 'active',
                        'payment_status' => 'paid',
                        'xendit_payment_id' => $invoiceData['payment_id'] ?? null,
                    ]);

                    // Send payment confirmation email
                    $plan = $this->getPlanDetails($subscription->plan_id);
                    Mail::to($subscription->user->email)
                        ->send(new PaymentConfirmationMail($subscription, $plan));

                    return response()->json([
                        'message' => 'Payment status updated to paid',
                        'subscription' => $subscription->fresh(),
                        'invoice_status' => $invoiceData['status']
                    ]);
                } else {
                    return response()->json([
                        'message' => 'Payment is still pending',
                        'subscription' => $subscription,
                        'invoice_status' => $invoiceData['status']
                    ]);
                }
            } else {
                return response()->json([
                    'message' => 'Failed to check payment status',
                    'error' => $response->json()
                ], 500);
            }
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error checking payment status',
                'error' => $e->getMessage()
            ], 500);
        }
    }
} 