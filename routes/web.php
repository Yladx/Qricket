<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SubscriptionController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Subscription routes
    Route::get('/subscription', [SubscriptionController::class, 'index'])->name('subscription.index');
    Route::post('/subscription', [SubscriptionController::class, 'create'])->name('subscription.create');
    Route::get('/subscription/success', [SubscriptionController::class, 'success'])->name('subscription.success');
    Route::get('/subscription/failure', [SubscriptionController::class, 'failure'])->name('subscription.failure');
    Route::post('/subscription/webhook', [SubscriptionController::class, 'webhook'])->name('subscription.webhook');
    
    // Payment status check route (for debugging)
    Route::get('/subscription/{subscription}/check-payment', [SubscriptionController::class, 'checkPaymentStatus'])
        ->name('subscription.check-payment');
});

// Test webhook route for payload verification (no authentication required)
Route::post('/test-webhook', function (\Illuminate\Http\Request $request) {
    $payload = $request->all();
    $headers = $request->headers->all();
    
    // Log everything for debugging
    Log::info('Test webhook received', [
        'payload' => $payload,
        'headers' => $headers,
        'method' => $request->method(),
        'url' => $request->fullUrl(),
        'ip' => $request->ip(),
        'user_agent' => $request->userAgent(),
        'content_type' => $request->header('Content-Type'),
        'timestamp' => now()->toISOString(),
    ]);
    
    // Save to file for easy inspection
    try {
        $testWebhooksDir = storage_path('app/test-webhooks');
        if (!file_exists($testWebhooksDir)) {
            mkdir($testWebhooksDir, 0755, true);
        }
        
        $timestamp = now()->format('Y-m-d_H-i-s');
        $filename = "test_webhook_{$timestamp}.json";
        $filepath = $testWebhooksDir . '/' . $filename;
        
        $dataToSave = [
            'received_at' => now()->toISOString(),
            'payload' => $payload,
            'headers' => $headers,
            'request_info' => [
                'method' => $request->method(),
                'url' => $request->fullUrl(),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'content_type' => $request->header('Content-Type'),
            ],
            'file_info' => [
                'filename' => $filename,
                'saved_at' => now()->toISOString(),
            ]
        ];
        
        file_put_contents($filepath, json_encode($dataToSave, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        Log::info('Test webhook data saved to file', ['filepath' => $filepath]);
        
    } catch (\Exception $e) {
        Log::error('Failed to save test webhook data', ['error' => $e->getMessage()]);
    }
    
    // Return success response
    return response()->json([
        'status' => 'success',
        'message' => 'Test webhook received and logged',
        'received_at' => now()->toISOString(),
        'payload_keys' => array_keys($payload),
        'headers_keys' => array_keys($headers),
    ]);
})->name('test.webhook');

// GET route to test if the webhook endpoint is accessible
Route::get('/test-webhook', function () {
    return response()->json([
        'status' => 'success',
        'message' => 'Test webhook endpoint is accessible',
        'endpoint' => '/test-webhook',
        'methods' => ['GET', 'POST'],
        'description' => 'Use POST to send test payloads, GET to verify endpoint accessibility',
        'timestamp' => now()->toISOString(),
    ]);
})->name('test.webhook.get');

// Email preview routes (only in development)
if (app()->environment('local')) {
    Route::get('/email/preview/invoice', function () {
        $subscription = \App\Models\Subscription::with('user')->first();
        if (!$subscription) {
            return 'No subscription found. Please create a subscription first.';
        }
        
        $plan = [
            'id' => $subscription->plan_id,
            'name' => ucfirst($subscription->plan_id) . ' Plan',
            'price' => $subscription->amount,
        ];
        
        $invoiceUrl = route('subscription.success') . '?test=true';
        
        return view('emails.invoice', [
            'subscription' => $subscription,
            'invoiceUrl' => $invoiceUrl,
            'planDetails' => $plan,
            'user' => $subscription->user,
        ]);
    })->name('email.preview.invoice');

    Route::get('/email/preview/payment-confirmation', function () {
        $subscription = \App\Models\Subscription::with('user')->first();
        if (!$subscription) {
            return 'No subscription found. Please create a subscription first.';
        }
        
        $plan = [
            'id' => $subscription->plan_id,
            'name' => ucfirst($subscription->plan_id) . ' Plan',
            'price' => $subscription->amount,
        ];
        
        return view('emails.payment-confirmation', [
            'subscription' => $subscription,
            'planDetails' => $plan,
            'user' => $subscription->user,
        ]);
    })->name('email.preview.payment-confirmation');
}

require __DIR__.'/auth.php';
