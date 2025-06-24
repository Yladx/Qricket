<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SubscriptionController;
use Illuminate\Support\Facades\Route;

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
