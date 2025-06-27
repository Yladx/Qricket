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

    public function test_webhook_handles_xendit_invoice_paid_event()
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

        $response = $this->postJson('/webhook/xendit', $payload, [
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

    public function test_webhook_handles_invoice_paid_after_expiry()
    {
        $payload = [
            "id" => "579c8d61f23fa4ca35e52da5",
            "external_id" => "invoice_123124124",
            "status" => "PAID",
            "amount" => 50000,
            "paid_amount" => 50000,
            "payer_email" => "late.payer@example.com",
            "created" => "2016-10-10T08:15:03.404Z",
            "paid_at" => "2016-10-13T08:15:03.404Z", // 3 days later
            "currency" => "IDR"
        ];

        $response = $this->postJson('/webhook/xendit', $payload, [
            'x-callback-token' => config('services.xendit.callback_token')
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'success']);

        // Check that subscription was created and marked as active
        $this->assertDatabaseHas('subscriptions', [
            'xendit_invoice_id' => '579c8d61f23fa4ca35e52da5',
            'status' => 'active',
            'payment_status' => 'paid'
        ]);
    }

    public function test_webhook_handles_invoice_expired_event()
    {
        // Create a subscription first
        $subscription = Subscription::factory()->create([
            'xendit_invoice_id' => '579c8d61f23fa4ca35e52da6',
            'status' => 'active',
            'payment_status' => 'pending'
        ]);

        $payload = [
            "id" => "579c8d61f23fa4ca35e52da6",
            "external_id" => "invoice_123124125",
            "status" => "EXPIRED",
            "amount" => 50000,
            "currency" => "IDR"
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

    public function test_webhook_handles_user_data_extraction()
    {
        $payload = [
            "id" => "579c8d61f23fa4ca35e52da7",
            "external_id" => "invoice_123124126",
            "status" => "PAID",
            "amount" => 50000,
            "paid_amount" => 50000,
            "payer_email" => "john.doe@example.com",
            "customer" => [
                "given_names" => "John",
                "surname" => "Doe",
                "email" => "john.doe@example.com"
            ],
            "currency" => "IDR"
        ];

        $response = $this->postJson('/webhook/xendit', $payload, [
            'x-callback-token' => config('services.xendit.callback_token')
        ]);

        $response->assertStatus(200);

        // Check that user was created with proper name
        $this->assertDatabaseHas('users', [
            'email' => 'john.doe@example.com',
            'first_name' => 'John',
            'last_name' => 'Doe'
        ]);
    }

    public function test_webhook_handles_duplicate_payment()
    {
        // Create an existing subscription
        $subscription = Subscription::factory()->create([
            'xendit_invoice_id' => '579c8d61f23fa4ca35e52da8',
            'status' => 'active',
            'payment_status' => 'paid'
        ]);

        $payload = [
            "id" => "579c8d61f23fa4ca35e52da8",
            "external_id" => "invoice_123124127",
            "status" => "PAID",
            "amount" => 50000,
            "paid_amount" => 50000,
            "payer_email" => "duplicate@example.com"
        ];

        $response = $this->postJson('/webhook/xendit', $payload, [
            'x-callback-token' => config('services.xendit.callback_token')
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'already_processed']);
    }

    public function test_webhook_rejects_invalid_token()
    {
        $payload = [
            "id" => "579c8d61f23fa4ca35e52da9",
            "status" => "PAID",
            "amount" => 50000
        ];

        $response = $this->postJson('/webhook/xendit', $payload, [
            'x-callback-token' => 'invalid_token'
        ]);

        $response->assertStatus(401);
        $response->assertJson(['error' => 'Invalid token']);
    }

    public function test_webhook_handles_unknown_event_type()
    {
        $payload = [
            "id" => "579c8d61f23fa4ca35e52daa",
            "status" => "UNKNOWN_STATUS"
        ];

        $response = $this->postJson('/webhook/xendit', $payload, [
            'x-callback-token' => config('services.xendit.callback_token')
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'ignored']);
    }
} 