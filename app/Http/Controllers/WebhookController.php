<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function handlePaymentWebhook(Request $request)
    {
        // Log the payload for debugging
        Log::info('Received payment webhook:', $request->all());

        // Example: Handle Xendit invoice paid event
        $event = $request->input('event');
        $data = $request->input('data');

        if ($event === 'invoice.paid') {
            // Find the subscription by Xendit invoice ID
            $subscription = \App\Models\Subscription::where('xendit_invoice_id', $data['id'])->first();
            if ($subscription) {
                $subscription->status = 'active';
                $subscription->payment_status = 'paid';
                $subscription->save();
                return response()->json(['status' => 'success', 'message' => 'Subscription marked as paid and active.']);
            } else {
                return response()->json(['status' => 'error', 'message' => 'Subscription not found.'], 404);
            }
        }

        // For other events or if not handled
        return response()->json(['status' => 'ignored']);
    }
} 