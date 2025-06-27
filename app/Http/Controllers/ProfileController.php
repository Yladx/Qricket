<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $originalData = $request->user()->only(['first_name', 'last_name', 'email', 'organizer']);
        
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        // Log profile update
        $changes = [];
        foreach (['first_name', 'last_name', 'email', 'organizer'] as $field) {
            if ($originalData[$field] !== $request->user()->$field) {
                $changes[$field] = [
                    'from' => $originalData[$field],
                    'to' => $request->user()->$field
                ];
            }
        }

        $request->user()->logActivity('profile_update', 'Profile information updated', [
            'updated_fields' => array_keys($changes),
            'changes' => $changes,
            'user_agent' => $request->userAgent(),
            'ip_address' => $request->ip()
        ]);

        // Log organizer status change specifically if it changed
        if (isset($changes['organizer'])) {
            $status = $changes['organizer']['to'] ? 'enabled' : 'disabled';
            $request->user()->logActivity('organizer_status_changed', "Organizer status {$status}", [
                'previous_status' => $changes['organizer']['from'],
                'new_status' => $changes['organizer']['to'],
                'user_agent' => $request->userAgent(),
                'ip_address' => $request->ip()
            ]);
        }

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        // Log account deletion before deleting
        $user->logActivity('account_deleted', 'User account deleted', [
            'deletion_method' => 'user_request',
            'user_agent' => $request->userAgent(),
            'ip_address' => $request->ip()
        ]);

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
