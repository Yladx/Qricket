<?php

namespace App\Http\Controllers;

use App\Mail\PaymentConfirmationMail;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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
                    return $this->handlePaymentCompleted($payload);
                case 'payment.failed':
                    return $this->handlePaymentFailed($payload);
                case 'disbursement.completed':
                    return $this->handleDisbursementCompleted($payload);
                case 'disbursement.failed':
                    return $this->handleDisbursementFailed($payload);
                case 'refund.completed':
                    return $this->handleRefundCompleted($payload);
                case 'refund.failed':
                    return $this->handleRefundFailed($payload);
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
     * Determine the event type from the webhook payload
     */
    private function determineEventType($payload)
    {
        // Check for explicit event type
        if (isset($payload['event'])) {
            return $payload['event'];
        }

        // Infer from status and other fields
        $status = $payload['status'] ?? null;
        $type = $payload['type'] ?? null;

        if ($type === 'INVOICE') {
            switch ($status) {
                case 'PAID':
                    return 'invoice.paid';
                case 'EXPIRED':
                    return 'invoice.expired';
                case 'CANCELLED':
                    return 'invoice.cancelled';
            }
        }

        if ($type === 'PAYMENT') {
            switch ($status) {
                case 'COMPLETED':
                    return 'payment.completed';
                case 'FAILED':
                    return 'payment.failed';
            }
        }

        if ($type === 'DISBURSEMENT') {
            switch ($status) {
                case 'COMPLETED':
                    return 'disbursement.completed';
                case 'FAILED':
                    return 'disbursement.failed';
            }
        }

        if ($type === 'REFUND') {
            switch ($status) {
                case 'COMPLETED':
                    return 'refund.completed';
                case 'FAILED':
                    return 'refund.failed';
            }
        }

        return 'unknown';
    }

    /**
     * Handle invoice.paid event
     */
    private function handleInvoicePaid($payload)
    {
        $subscription = Subscription::where('xendit_invoice_id', $payload['id'])->first();
        
        if (!$subscription) {
            // Create the subscription record if it doesn't exist
            $subscription = $this->createSubscriptionFromWebhook($payload);
        }

        if (!$subscription) {
            Log::error('Failed to create or find subscription for paid invoice', [
                'invoice_id' => $payload['id'],
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
            $this->sendPaymentConfirmationEmail($subscription);

            return response()->json(['status' => 'success']);

        } catch (\Exception $e) {
            Log::error('Failed to update subscription payment status', [
                'subscription_id' => $subscription->id,
                'xendit_invoice_id' => $payload['id'],
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
     * Create subscription from webhook payload
     */
    private function createSubscriptionFromWebhook($payload)
    {
        try {
            $user = User::where('email', $payload['customer']['email'] ?? null)->first();
            $planId = $this->inferPlanFromAmount($payload['amount'] ?? null);
            $amount = $payload['amount'] ?? null;

            $subscription = Subscription::create([
                'user_id' => $user ? $user->id : null,
                'plan_id' => $planId,
                'status' => 'active',
                'xendit_invoice_id' => $payload['id'],
                'xendit_payment_id' => $payload['payment_id'] ?? null,
                'amount' => $amount,
                'currency' => $payload['currency'] ?? 'PHP',
                'start_date' => now(),
                'end_date' => now()->addMonth(),
                'payment_status' => 'paid',
            ]);

            Log::info('Subscription created from webhook', [
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'plan_id' => $subscription->plan_id,
                'invoice_id' => $payload['id']
            ]);

            return $subscription;

        } catch (\Exception $e) {
            Log::error('Failed to create subscription from webhook', [
                'error' => $e->getMessage(),
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
        return match($amount) {
            199 => 'basic',
            399 => 'pro',
            999 => 'enterprise',
            default => 'unknown'
        };
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
}
