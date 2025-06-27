<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationNotificationController extends Controller
{
    /**
     * Send a new email verification notification.
     */
    public function store(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard', absolute: false));
        }

        $request->user()->sendEmailVerificationNotification();

        // Log email verification notification sent
        $request->user()->logActivity('email_verification_sent', 'Email verification notification sent', [
            'notification_method' => 'email',
            'user_agent' => $request->userAgent(),
            'ip_address' => $request->ip()
        ]);

        return back()->with('status', 'verification-link-sent');
    }
}
