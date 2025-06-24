<?php

namespace App\Console\Commands;

use App\Mail\PaymentConfirmationMail;
use App\Models\Subscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendPaymentConfirmationEmail extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email:send-payment-confirmation {subscription_id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send payment confirmation email for a specific subscription';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $subscriptionId = $this->argument('subscription_id');
        
        $subscription = Subscription::with('user')->find($subscriptionId);
        
        if (!$subscription) {
            $this->error("Subscription with ID {$subscriptionId} not found.");
            return 1;
        }

        if ($subscription->payment_status !== 'paid') {
            $this->error("Subscription payment status is not 'paid'. Current status: {$subscription->payment_status}");
            return 1;
        }

        $plan = $this->getPlanDetails($subscription->plan_id);
        
        try {
            Mail::to($subscription->user->email)
                ->send(new PaymentConfirmationMail($subscription, $plan));
            
            $this->info("Payment confirmation email sent successfully to {$subscription->user->email}");
            $this->info("Subscription: {$plan['name']} - ₱{$plan['price']}");
            $this->info("Payment ID: {$subscription->xendit_payment_id}");
            
        } catch (\Exception $e) {
            $this->error("Failed to send payment confirmation email: " . $e->getMessage());
            return 1;
        }

        return 0;
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