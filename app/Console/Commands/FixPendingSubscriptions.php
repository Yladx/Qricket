<?php

namespace App\Console\Commands;

use App\Mail\PaymentConfirmationMail;
use App\Models\Subscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class FixPendingSubscriptions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscription:fix-pending {--subscription-id= : Specific subscription ID to check} {--all : Check all pending subscriptions}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix subscriptions stuck in pending status by checking with Xendit API';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $subscriptionId = $this->option('subscription-id');
        $checkAll = $this->option('all');

        if ($subscriptionId) {
            $this->checkSpecificSubscription($subscriptionId);
        } elseif ($checkAll) {
            $this->checkAllPendingSubscriptions();
        } else {
            $this->error('Please specify either --subscription-id or --all option');
            return 1;
        }

        return 0;
    }

    private function checkSpecificSubscription($subscriptionId)
    {
        $subscription = Subscription::with('user')->find($subscriptionId);
        
        if (!$subscription) {
            $this->error("Subscription with ID {$subscriptionId} not found.");
            return;
        }

        $this->info("Checking subscription ID: {$subscriptionId}");
        $this->info("Current status: {$subscription->payment_status}");
        $this->info("Xendit Invoice ID: {$subscription->xendit_invoice_id}");

        $this->checkAndUpdateSubscription($subscription);
    }

    private function checkAllPendingSubscriptions()
    {
        $pendingSubscriptions = Subscription::with('user')
            ->where('payment_status', 'pending')
            ->get();

        if ($pendingSubscriptions->isEmpty()) {
            $this->info('No pending subscriptions found.');
            return;
        }

        $this->info("Found {$pendingSubscriptions->count()} pending subscriptions.");

        foreach ($pendingSubscriptions as $subscription) {
            $this->info("\nChecking subscription ID: {$subscription->id}");
            $this->info("User: {$subscription->user->email}");
            $this->info("Plan: {$subscription->plan_id}");
            $this->info("Amount: ₱{$subscription->amount}");
            
            $this->checkAndUpdateSubscription($subscription);
        }
    }

    private function checkAndUpdateSubscription($subscription)
    {
        try {
            $response = Http::withBasicAuth(config('services.xendit.api_key'), '')
                ->get("https://api.xendit.co/v2/invoices/{$subscription->xendit_invoice_id}");

            if (!$response->successful()) {
                $this->error("Failed to check Xendit invoice: " . $response->status());
                $this->error("Response: " . $response->body());
                return;
            }

            $invoiceData = $response->json();
            $this->info("Xendit invoice status: {$invoiceData['status']}");

            if ($invoiceData['status'] === 'PAID') {
                // Update subscription status
                $subscription->update([
                    'status' => 'active',
                    'payment_status' => 'paid',
                    'xendit_payment_id' => $invoiceData['payment_id'] ?? null,
                ]);

                $this->info("✅ Subscription updated to PAID status");

                // Send payment confirmation email
                try {
                    $plan = $this->getPlanDetails($subscription->plan_id);
                    Mail::to($subscription->user->email)
                        ->send(new PaymentConfirmationMail($subscription, $plan));

                    $this->info("✅ Payment confirmation email sent to {$subscription->user->email}");

                    Log::info('Subscription fixed via command', [
                        'subscription_id' => $subscription->id,
                        'user_email' => $subscription->user->email,
                        'xendit_invoice_id' => $subscription->xendit_invoice_id,
                        'payment_id' => $invoiceData['payment_id'] ?? null
                    ]);

                } catch (\Exception $e) {
                    $this->error("❌ Failed to send payment confirmation email: " . $e->getMessage());
                }
            } else {
                $this->warn("⚠️  Invoice is still {$invoiceData['status']}");
            }

        } catch (\Exception $e) {
            $this->error("❌ Error checking subscription: " . $e->getMessage());
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
} 