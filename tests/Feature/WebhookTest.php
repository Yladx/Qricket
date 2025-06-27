<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock the Xendit API call
        Http::fake([
            'api.xendit.co/*' => Http::response([
                'status' => 'PAID',
                'amount' => 199,
                'currency' => 'PHP'
            ], 200)
        ]);
    }

    public function test_webhook_handles_invoice_paid_event()
    {
        $payload = [
            'id' => 'inv_test_123',
            'items' => [
                [
                    'name' => 'Basic Plan',
                    'price' => 199,
                    'category' => 'Subscription',
                    'quantity' => 1
                ]
            ],
            'amount' => 199,
            'status' => 'PAID',
            'created' => '2025-06-27T22:45:56.982Z',
            'paid_at' => '2025-06-27T22:46:02.624Z',
            'updated' => '2025-06-27T22:46:04.848Z',
            'user_id' => 'user_test_123',
            'currency' => 'PHP',
            'payment_id' => 'pay_test_123',
            'description' => 'Subscription to Basic Plan',
            'external_id' => 'subscription-test-123',
            'paid_amount' => 199,
            'ewallet_type' => 'GCASH',
            'merchant_name' => 'Qricket',
            'payment_method' => 'EWALLET',
            'payment_channel' => 'GCASH'
        ];

        $response = $this->postJson('/webhook/xendit', $payload, [
            'x-callback-token' => config('services.xendit.callback_token')
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'success']);

        // Check that subscription was created
        $this->assertDatabaseHas('subscriptions', [
            'xendit_invoice_id' => 'inv_test_123',
            'xendit_payment_id' => 'pay_test_123',
            'amount' => 199,
            'currency' => 'PHP',
            'plan_id' => 'basic',
            'status' => 'active',
            'payment_status' => 'paid'
        ]);

        // Check that user was created
        $this->assertDatabaseHas('users', [
            'email' => 'john.doe@example.com',
            'first_name' => 'John',
            'last_name' => 'Doe'
        ]);
    }

    public function test_webhook_handles_payment_succeeded_event()
    {
        $payload = [
            'id' => 'inv_test_456',
            'items' => [
                [
                    'name' => 'Pro Plan',
                    'price' => 399,
                    'category' => 'Subscription',
                    'quantity' => 1
                ]
            ],
            'amount' => 399,
            'status' => 'PAID',
            'user_id' => 'user_test_456',
            'currency' => 'PHP',
            'payment_id' => 'pay_test_456',
            'description' => 'Subscription to Pro Plan',
            'external_id' => 'subscription-test-456',
            'paid_amount' => 399,
            'payment_method' => 'EWALLET',
            'payment_channel' => 'GCASH'
        ];

        $response = $this->postJson('/webhook/xendit', $payload, [
            'x-callback-token' => config('services.xendit.callback_token')
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'success']);

        // Check that subscription was created with pro plan
        $this->assertDatabaseHas('subscriptions', [
            'xendit_invoice_id' => 'inv_test_456',
            'plan_id' => 'pro',
            'amount' => 399
        ]);
    }

    public function test_webhook_handles_invoice_expired_event()
    {
        // Create a subscription first
        $subscription = Subscription::factory()->create([
            'xendit_invoice_id' => 'inv_test_789',
            'status' => 'active',
            'payment_status' => 'pending'
        ]);

        $payload = [
            'id' => 'inv_test_789',
            'status' => 'EXPIRED',
            'amount' => 199,
            'currency' => 'PHP'
        ];

        $response = $this->postJson('/webhook/xendit', $payload, [
            'x-callback-token' => config('services.xendit.callback_token')
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'success']);

        // Check that subscription status was updated
        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscription->id,
            'status' => 'expired',
            'payment_status' => 'expired'
        ]);
    }

    public function test_webhook_handles_invoice_cancelled_event()
    {
        // Create a subscription first
        $subscription = Subscription::factory()->create([
            'xendit_invoice_id' => 'inv_test_101',
            'status' => 'active',
            'payment_status' => 'pending'
        ]);

        $payload = [
            'id' => 'inv_test_101',
            'status' => 'CANCELLED',
            'amount' => 199,
            'currency' => 'PHP'
        ];

        $response = $this->postJson('/webhook/xendit', $payload, [
            'x-callback-token' => config('services.xendit.callback_token')
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'success']);

        // Check that subscription status was updated
        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscription->id,
            'status' => 'cancelled',
            'payment_status' => 'cancelled'
        ]);
    }

    public function test_webhook_handles_payment_failed_event()
    {
        // Create a subscription first
        $subscription = Subscription::factory()->create([
            'xendit_invoice_id' => 'inv_test_202',
            'status' => 'active',
            'payment_status' => 'pending'
        ]);

        $payload = [
            'id' => 'inv_test_202',
            'status' => 'FAILED',
            'amount' => 199,
            'currency' => 'PHP'
        ];

        $response = $this->postJson('/webhook/xendit', $payload, [
            'x-callback-token' => config('services.xendit.callback_token')
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'success']);

        // Check that subscription status was updated
        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscription->id,
            'status' => 'failed',
            'payment_status' => 'failed'
        ]);
    }

    public function test_webhook_rejects_invalid_token()
    {
        $payload = [
            'id' => 'inv_test_123',
            'status' => 'PAID',
            'amount' => 199
        ];

        $response = $this->postJson('/webhook/xendit', $payload, [
            'x-callback-token' => 'invalid_token'
        ]);

        $response->assertStatus(401);
        $response->assertJson(['error' => 'Invalid token']);
    }

    public function test_webhook_handles_plan_extraction_from_items()
    {
        $payload = [
            'id' => 'inv_test_303',
            'items' => [
                [
                    'name' => 'Enterprise Plan',
                    'price' => 999,
                    'category' => 'Subscription',
                    'quantity' => 1
                ]
            ],
            'status' => 'PAID',
            'amount' => 999,
            'currency' => 'PHP',
            'payment_id' => 'pay_test_303'
        ];

        $response = $this->postJson('/webhook/xendit', $payload, [
            'x-callback-token' => config('services.xendit.callback_token')
        ]);

        $response->assertStatus(200);

        // Check that subscription was created with enterprise plan
        $this->assertDatabaseHas('subscriptions', [
            'xendit_invoice_id' => 'inv_test_303',
            'plan_id' => 'enterprise',
            'amount' => 999
        ]);
    }

    public function test_webhook_handles_duplicate_payment()
    {
        // Create an existing subscription
        $subscription = Subscription::factory()->create([
            'xendit_invoice_id' => 'inv_test_404',
            'status' => 'active',
            'payment_status' => 'paid'
        ]);

        $payload = [
            'id' => 'inv_test_404',
            'status' => 'PAID',
            'amount' => 199
        ];

        $response = $this->postJson('/webhook/xendit', $payload, [
            'x-callback-token' => config('services.xendit.callback_token')
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'already_processed']);
    }

    public function test_webhook_handles_unknown_event_type()
    {
        $payload = [
            'id' => 'inv_test_505',
            'status' => 'UNKNOWN_STATUS'
        ];

        $response = $this->postJson('/webhook/xendit', $payload, [
            'x-callback-token' => config('services.xendit.callback_token')
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'ignored']);
    }
} 