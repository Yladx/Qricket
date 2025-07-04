<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class XenditWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock the Xendit API call
        Http::fake([
            'api.xendit.co/*' => Http::response([
                'status' => 'PAID',
                'amount' => 50000,
                'currency' => 'IDR'
            ], 200)
        ]);
    }

    public function test_webhook_handles_invoice_paid_event_with_specific_payload()
    {
        $payload = [
            "id" => "579c8d61f23fa4ca35e52da4",
            "external_id" => "invoice_123124123",
            "user_id" => "5781d19b2e2385880609791c",
            "is_high" => true,
            "payment_method" => "BANK_TRANSFER",
            "status" => "PAID",
            "merchant_name" => "Xendit",
            "amount" => 50000,
            "paid_amount" => 50000,
            "bank_code" => "PERMATA",
            "paid_at" => "2016-10-12T08:15:03.404Z",
            "payer_email" => "wildan@xendit.co",
            "description" => "This is a description",
            "adjusted_received_amount" => 47500,
            "fees_paid_amount" => 0,
            "updated" => "2016-10-10T08:15:03.404Z",
            "created" => "2016-10-10T08:15:03.404Z",
            "currency" => "IDR",
            "payment_channel" => "PERMATA",
            "payment_destination" => "888888888888"
        ];

        $response = $this->postJson('/xendit/webhook', $payload, [
            'x-callback-token' => config('services.xendit.callback_token')
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'success']);

        // Check that subscription was created
        $this->assertDatabaseHas('subscriptions', [
            'xendit_invoice_id' => '579c8d61f23fa4ca35e52da4',
            'xendit_payment_id' => '579c8d61f23fa4ca35e52da4',
            'amount' => 50000,
            'currency' => 'IDR',
            'plan_id' => 'basic',
            'status' => 'active',
            'payment_status' => 'paid'
        ]);

        // Check that user was created
        $this->assertDatabaseHas('users', [
            'email' => 'wildan@xendit.co',
            'first_name' => 'Unknown',
            'last_name' => 'User'
        ]);
    }

    public function test_webhook_handles_invoice_expired_event()
    {
        // Create a subscription first
        $subscription = Subscription::factory()->create([
            'xendit_invoice_id' => '579c8d61f23fa4ca35e52da4',
            'status' => 'active',
            'payment_status' => 'pending'
        ]);

        $payload = [
            "id" => "579c8d61f23fa4ca35e52da4",
            "external_id" => "invoice_123124123",
            "status" => "EXPIRED",
            "amount" => 50000,
            "currency" => "IDR"
        ];

        $response = $this->postJson('/xendit/webhook', $payload, [
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

    public function test_webhook_handles_payment_after_expiry_event()
    {
        // Create an expired subscription first
        $subscription = Subscription::factory()->create([
            'xendit_invoice_id' => '579c8d61f23fa4ca35e52da4',
            'status' => 'expired',
            'payment_status' => 'expired'
        ]);

        $payload = [
            "id" => "579c8d61f23fa4ca35e52da4",
            "external_id" => "invoice_123124123",
            "status" => "PAID",
            "amount" => 50000,
            "paid_amount" => 50000,
            "paid_at" => "2016-10-12T08:15:03.404Z",
            "payer_email" => "wildan@xendit.co",
            "currency" => "IDR"
        ];

        $response = $this->postJson('/xendit/webhook', $payload, [
            'x-callback-token' => config('services.xendit.callback_token')
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'success']);

        // Check that subscription was reactivated
        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscription->id,
            'status' => 'active',
            'payment_status' => 'paid'
        ]);
    }

    public function test_webhook_rejects_invalid_token()
    {
        $payload = [
            "id" => "579c8d61f23fa4ca35e52da4",
            "status" => "PAID",
            "amount" => 50000
        ];

        $response = $this->postJson('/xendit/webhook', $payload, [
            'x-callback-token' => 'invalid_token'
        ]);

        $response->assertStatus(401);
        $response->assertJson(['error' => 'Invalid token']);
    }

    public function test_webhook_handles_duplicate_payment()
    {
        // Create an existing paid subscription
        $subscription = Subscription::factory()->create([
            'xendit_invoice_id' => '579c8d61f23fa4ca35e52da4',
            'status' => 'active',
            'payment_status' => 'paid'
        ]);

        $payload = [
            "id" => "579c8d61f23fa4ca35e52da4",
            "external_id" => "invoice_123124123",
            "status" => "PAID",
            "amount" => 50000,
            "currency" => "IDR"
        ];

        $response = $this->postJson('/xendit/webhook', $payload, [
            'x-callback-token' => config('services.xendit.callback_token')
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'already_processed']);
    }

    public function test_webhook_handles_unknown_event_type()
    {
        $payload = [
            "id" => "579c8d61f23fa4ca35e52da4",
            "event" => "unknown.event",
            "amount" => 50000
        ];

        $response = $this->postJson('/xendit/webhook', $payload, [
            'x-callback-token' => config('services.xendit.callback_token')
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'ignored']);
    }

    public function test_webhook_handles_missing_invoice_id()
    {
        $payload = [
            "status" => "PAID",
            "amount" => 50000,
            "currency" => "IDR"
        ];

        $response = $this->postJson('/xendit/webhook', $payload, [
            'x-callback-token' => config('services.xendit.callback_token')
        ]);

        $response->assertStatus(400);
        $response->assertJson(['error' => 'No invoice ID found']);
    }

    public function test_webhook_handles_new_payload_format_with_items()
    {
        $payload = [
            "id" => "685f24e1be0ebcaffb7dc1c4",
            "items" => [
                [
                    "name" => "Pro Plan",
                    "price" => 399,
                    "category" => "Subscription",
                    "quantity" => 1
                ]
            ],
            "amount" => 399,
            "status" => "PAID",
            "created" => "2025-06-27T23:10:25.958Z",
            "is_high" => false,
            "paid_at" => "2025-06-27T23:10:39.163Z",
            "updated" => "2025-06-27T23:10:41.463Z",
            "user_id" => "684060e43f893d8cba58d2d6",
            "currency" => "PHP",
            "payment_id" => "ewc_dfec4f18-32c4-4b3a-a84b-9e9849af78d0",
            "description" => "Subscription to Pro Plan",
            "external_id" => "subscription-685f24e26ff80",
            "paid_amount" => 399,
            "ewallet_type" => "GCASH",
            "merchant_name" => "Qricket",
            "payment_method" => "EWALLET",
            "payment_channel" => "GCASH",
            "payment_method_id" => "pm-07293af4-3028-462d-803c-21bdf53c8d0f"
        ];

        $response = $this->postJson('/xendit/webhook', $payload, [
            'x-callback-token' => config('services.xendit.callback_token')
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'success']);

        // Check that subscription was created with pro plan
        $this->assertDatabaseHas('subscriptions', [
            'xendit_invoice_id' => '685f24e1be0ebcaffb7dc1c4',
            'xendit_payment_id' => 'ewc_dfec4f18-32c4-4b3a-a84b-9e9849af78d0',
            'amount' => 399,
            'currency' => 'PHP',
            'plan_id' => 'pro',
            'status' => 'active',
            'payment_status' => 'paid'
        ]);
    }
} 