<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }

    public function test_xendit_invoice_paid_webhook_is_handled_and_saved(): void
    {
        $payload = [
            'id' => 'test_invoice_id',
            'status' => 'PAID',
            'type' => 'INVOICE',
            'amount' => 199,
            'currency' => 'PHP',
            'payment_id' => 'test_payment_id',
            'customer' => [
                'email' => 'testuser@example.com',
            ],
        ];

        // Create a user for the webhook to match
        \App\Models\User::factory()->create([
            'email' => 'testuser@example.com',
        ]);

        $response = $this->postJson('/xendit/webhook', $payload, [
            'x-callback-token' => config('services.xendit.callback_token'),
        ]);

        $response->assertStatus(200)
            ->assertJson(['status' => 'success']);

        // Assert the webhook file was saved
        $files = glob(storage_path('app/webhooks/webhook*_invoice.paid*_test_invoice_id.json'));
        $this->assertNotEmpty($files, 'Webhook file was not saved');
    }
}
