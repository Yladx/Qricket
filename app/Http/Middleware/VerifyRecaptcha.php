<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class VerifyRecaptcha
{

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('login') || $request->is('register')) {
            if ($request->isMethod('post')) {
                $response = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
                    'secret' => config('services.recaptcha.secret_key'),
                    'response' => $request->input('g-recaptcha-response'),
                    'remoteip' => $request->ip(),
                ]);

                if (!$response->json('success')) {
                    return back()->withErrors([
                        'g-recaptcha-response' => 'Please complete the reCAPTCHA verification.',
                    ])->withInput();
                }
            }
        }
        return $next($request);
    }
} 