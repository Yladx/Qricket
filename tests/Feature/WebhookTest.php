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
            'event' => 'invoice.paid',
            'id' => 'inv_test_123',
            'status' => 'PAID',
            'amount' => 199,
            'currency' => 'PHP',
            'customer' => [
                'given_names' => 'John',
                'surname' => 'Doe',
                'email' => 'john.doe@example.com'
            ],
            'payment_id' => 'pay_test_123'
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
            'event' => 'payment.succeeded',
            'id' => 'inv_test_456',
            'status' => 'SUCCEEDED',
            'amount' => 399,
            'currency' => 'PHP',
            'customer' => [
                'given_names' => 'Jane',
                'surname' => 'Smith',
                'email' => 'jane.smith@example.com'
            ],
            'payment_id' => 'pay_test_456'
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
            'event' => 'invoice.expired',
            'id' => 'inv_test_789',
            'status' => 'EXPIRED'
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
            'event' => 'invoice.cancelled',
            'id' => 'inv_test_101',
            'status' => 'CANCELLED'
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
            'event' => 'payment.failed',
            'id' => 'inv_test_202',
            'status' => 'FAILED'
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
            'event' => 'invoice.paid',
            'id' => 'inv_test_123'
        ];

        $response = $this->postJson('/webhook/xendit', $payload, [
            'x-callback-token' => 'invalid_token'
        ]);

        $response->assertStatus(401);
        $response->assertJson(['error' => 'Invalid token']);
    }

    public function test_webhook_handles_user_data_extraction()
    {
        $payload = [
            'event' => 'invoice.paid',
            'id' => 'inv_test_303',
            'status' => 'PAID',
            'amount' => 999,
            'customer' => [
                'name' => 'Alice Johnson', // Full name instead of separate fields
                'email' => 'alice.johnson@example.com'
            ]
        ];

        $response = $this->postJson('/webhook/xendit', $payload, [
            'x-callback-token' => config('services.xendit.callback_token')
        ]);

        $response->assertStatus(200);

        // Check that user was created with split name
        $this->assertDatabaseHas('users', [
            'email' => 'alice.johnson@example.com',
            'first_name' => 'Alice',
            'last_name' => 'Johnson'
        ]);

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
            'event' => 'invoice.paid',
            'id' => 'inv_test_404',
            'status' => 'PAID'
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
            'event' => 'unknown.event',
            'id' => 'inv_test_505'
        ];

        $response = $this->postJson('/webhook/xendit', $payload, [
            'x-callback-token' => config('services.xendit.callback_token')
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'ignored']);
    }
} 