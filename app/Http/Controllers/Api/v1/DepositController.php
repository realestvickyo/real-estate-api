<?php
// 📁 File: app/Http/Controllers/Api/V1/DepositController.php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DepositController extends Controller
{
    /**
     * Initialize a platform deposit via Paystack Gateway Link
     */
    public function initializeDeposit(Request $request)
    {
        // 1. Validate inbound params safely
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'email' => 'required|email'
        ]);

        $amountInKsh = $request->input('amount');
        $email = $request->input('email');

        // Paystack tracks base operational currency units as cents/kobo (Multiply KES by 100)
        $amountInCents = $amountInKsh * 100; 

        try {
            // 2. Fetch Secret Key securely out of your environment file config mapping
            $paystackSecret = env('PAYSTACK_SECRET_KEY');

            if (!$paystackSecret) {
                return response()->json([
                    'error' => 'Backend Configuration Error: PAYSTACK_SECRET_KEY is missing from .env'
                ], 500);
            }

            // 3. Dispatch initialization command right to Paystack Endpoint Engine
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $paystackSecret,
                'Content-Type'  => 'application/json',
            ])->post('https://api.paystack.co/transaction/initialize', [
                'email' => $email,
                'amount' => $amountInCents,
                'currency' => 'KES',
                'callback_url' => 'http://localhost:5173/agent/escrows', // Point back to your premium tab view
                'metadata' => [
                    'transaction_type' => 'vault_deposit'
                ]
            ]);

            if ($response->failed()) {
                Log::error('Paystack API Initialization Error: ' . $response->body());
                return response()->json([
                    'error' => 'Failed communicating authorization parameters with Paystack.'
                ], 400);
            }

            $result = $response->json();

            // 4. Return structural routing parameters safely to your React app component handler
            return response()->json([
                'status' => true,
                'message' => 'Secure deposit authorization vector computed.',
                'paymentUrl' => $result['data']['authorization_url'] ?? null
            ]);

        } catch (\Exception $e) {
            Log::error('Fatal Exception on Vault Controller Line: ' . $e->getMessage());
            return response()->json([
                'error' => 'An internal server error occurred processing request vectors.'
            ], 500);
        }
    }
}