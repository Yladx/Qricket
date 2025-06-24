<?php

namespace App\Http\Controllers;

use App\Mail\InvoiceMail;
use App\Mail\PaymentConfirmationMail;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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

        $plan = $this->getPlanDetails($request->plan_id);
        
        // Create Xendit invoice
        $invoice = $this->createXenditInvoice($plan, $request->user());

        // Create subscription record
        $subscription = Subscription::create([
            'user_id' => $request->user()->id,
            'plan_id' => $plan['id'],
            'status' => 'pending',
            'xendit_invoice_id' => $invoice['id'],
            'amount' => $plan['price'],
            'currency' => 'PHP',
            'start_date' => now(),
            'end_date' => now()->addMonth(),
            'payment_status' => 'pending',
        ]);

        // Send invoice email to user
        try {
            Mail::to($request->user()->email)
                ->send(new InvoiceMail($subscription, $invoice['invoice_url'], $plan));
            
            Log::info('Invoice email sent successfully', [
                'user_id' => $request->user()->id,
                'user_email' => $request->user()->email,
                'subscription_id' => $subscription->id,
                'invoice_id' => $invoice['id']
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send invoice email', [
                'user_id' => $request->user()->id,
                'user_email' => $request->user()->email,
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage()
            ]);
        }

        return redirect($invoice['invoice_url']);
    }

    public function webhook(Request $request)
    {
        $payload = $request->all();
        $token = $request->header('x-callback-token');

        // Save webhook data to local storage for debugging
        $this->saveWebhookToStorage($payload, $token);

        if ($token !== config('services.xendit.callback_token')) {
            Log::warning('Invalid webhook token received', [
                'received_token' => $token,
                'expected_token' => config('services.xendit.callback_token'),
                'payload' => $payload
            ]);
            return response()->json(['error' => 'Invalid token'], 401);
        }

        Log::info('Xendit webhook received', $payload);

        // Only process PAID status
        if ($payload['status'] === 'PAID') {
            $subscription = Subscription::where('xendit_invoice_id', $payload['id'])->first();
            
            if (!$subscription) {
                Log::error('Subscription not found for webhook', [
                    'xendit_invoice_id' => $payload['id'],
                    'payload' => $payload
                ]);
                return response()->json(['error' => 'Subscription not found'], 404);
            }

            // Check if payment is already processed
            if ($subscription->payment_status === 'paid') {
                Log::info('Payment already processed for subscription', [
                    'subscription_id' => $subscription->id,
                    'xendit_invoice_id' => $payload['id']
                ]);
                return response()->json(['status' => 'already_processed']);
            }

            // Update subscription status
            try {
                $subscription->update([
                    'status' => 'active',
                    'payment_status' => 'paid',
                    'xendit_payment_id' => $payload['payment_id'] ?? null,
                ]);

                Log::info('Subscription payment status updated successfully', [
                    'subscription_id' => $subscription->id,
                    'user_id' => $subscription->user_id,
                    'payment_status' => 'paid',
                    'xendit_payment_id' => $payload['payment_id'] ?? null
                ]);

                // Send payment confirmation email
                try {
                    $plan = $this->getPlanDetails($subscription->plan_id);
                    
                    Mail::to($subscription->user->email)
                        ->send(new PaymentConfirmationMail($subscription, $plan));
                    
                    Log::info('Payment confirmation email sent successfully', [
                        'user_id' => $subscription->user_id,
                        'user_email' => $subscription->user->email,
                        'subscription_id' => $subscription->id,
                        'payment_id' => $payload['payment_id'] ?? null
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

            } catch (\Exception $e) {
                Log::error('Failed to update subscription payment status', [
                    'subscription_id' => $subscription->id,
                    'xendit_invoice_id' => $payload['id'],
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                return response()->json(['error' => 'Failed to update subscription'], 500);
            }
        } else {
            Log::info('Webhook received with non-PAID status', [
                'status' => $payload['status'],
                'xendit_invoice_id' => $payload['id'] ?? null
            ]);
        }

        return response()->json(['status' => 'success']);
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
                        'given_names' => $user->name,
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
                    'name' => $user->name,
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