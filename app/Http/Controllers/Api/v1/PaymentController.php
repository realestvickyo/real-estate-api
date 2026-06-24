<?php
// 📁 File: app/Http/Controllers/Api/v1/PaymentController.php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Payment;      
use App\Models\Property;     
use App\Models\Lead;              // 🌟 Added to track pipeline transition states
use App\Models\EscrowAgreement;  // 🌟 Added to auto-generate the escrow contract ledger
use App\Services\DarajaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

class PaymentController extends Controller
{
    protected DarajaService $darajaService;
    protected string $paystackSecret;

    public function __construct(DarajaService $darajaService)
    {
        $this->darajaService = $darajaService;
        $this->paystackSecret = config('services.paystack.secret_key') ?? env('PAYSTACK_SECRET_KEY', '');
    }

    /**
     * =========================================================================
     * 💳 PAYSTACK API ENGINE PIPELINES (Enhanced with Auto-Escrow Flow)
     * =========================================================================
     */

    /**
     * Verify Paystack Transaction Status via Reference Token
     * Route: GET /api/v1/payments/verify/{reference}
     */
    public function verifyPaystackPayment($reference)
    {
        Log::info("Executing secure validation verification for reference: {$reference}");

        try {
            // 1. Handshake directly with Paystack verification servers
            $response = Http::withToken($this->paystackSecret)
                ->get("https://api.paystack.co/transaction/verify/" . urlencode($reference));

            if ($response->failed()) {
                Log::error("Paystack verification endpoint handshake error for ref: {$reference}");
                return response()->json([
                    'status'  => false,
                    'message' => 'Could not connect with gateway settlement servers.'
                ], 400);
            }

            $paymentData = $response->json();

            // 2. Evaluate transaction state match with success vectors
            if (isset($paymentData['data']['status']) && $paymentData['data']['status'] === 'success') {
                
                DB::beginTransaction();

                $metadata = $paymentData['data']['metadata'] ?? [];
                $leadId = $metadata['lead_id'] ?? null;
                $propertyId = $metadata['property_id'] ?? null;
                $amountInKsh = $paymentData['data']['amount'] / 100; // Paystack returns values in minor units (cents)

                // Find payment logging entry by tracking reference
                $payment = Payment::where('payment_reference', $reference)->first();

                if (!$payment) {
                    // Fallback fallback block if an off-platform checkout payment hits this route
                    $payment = Payment::create([
                        'agency_id'         => 1,
                        'user_id'           => Auth::user()?->id ?? 1,
                        'property_id'       => $propertyId,
                        'amount'            => $amountInKsh,
                        'payment_reference' => $reference,
                        'status'            => 'held', // Match React frontend STATUS_STYLES color map
                        'metadata'          => $metadata
                    ]);
                } else {
                    $payment->update([
                        'status' => 'held'
                    ]);
                }

                // 🌟 AUTOMATED PIPELINE STEP: Update Lead status and establish the Escrow Agreement
                if ($leadId) {
                    $lead = Lead::find($leadId);
                    if ($lead) {
                        // 1. Permanently graduate the lead from the sales pipeline
                        $lead->update([
                            'kanban_stage' => 'closed_won',
                            'value'        => $amountInKsh
                        ]);

                        // 2. Automatically instantiate the Escrow Agreement record for admin tracking
                        EscrowAgreement::updateOrCreate(
                            ['paystack_reference' => $reference],
                            [
                                'lead_id'                 => $lead->id,
                                'property_id'             => $propertyId ?? $lead->property_id,
                                'client_name'             => $lead->name,
                                'client_email'            => $lead->email ?? 'client@makao-buyer.com',
                                'total_amount'            => $amountInKsh,
                                'status'                  => 'active', // Active vault, waiting for step configurations!
                                'current_milestone_index' => 0,
                                'metadata'                => [
                                    'channel'        => $paymentData['data']['channel'] ?? 'card',
                                    'currency'       => 'KES',
                                    'verified_at'    => now()->toIso8601String()
                                ]
                            ]
                        );

                        // 3. Flag the linked property asset state if applicable
                        if ($propertyId) {
                            Property::where('id', $propertyId)->update(['status' => 'under_contract']);
                        }
                    }
                }

                DB::commit();

                return response()->json([
                    'status'  => true,
                    'message' => 'Transaction verified. Lead pipeline graduated and Escrow Vault successfully generated.',
                    'data'    => $payment
                ], 200);
            }

            return response()->json([
                'status'  => false,
                'message' => 'Gateway states imply this transaction remains unauthorized or pending.'
            ], 400);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Critical transaction sync error on confirmation pipeline: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Internal server processing break: ' . $e->getMessage()
            ], 500);
        }
    }


    /**
     * =========================================================================
     * 📲 SAFARICOM DARAJA M-PESA PIPELINES (Original Logic Restored)
     * =========================================================================
     */

    /**
     * Trigger M-Pesa STK Push from React Frontend
     * Route: POST /api/v1/payments/stk-push
     */
    public function stkPush(Request $request)
    {
        $request->validate([
            'property_id'  => 'required',
            'phone_number' => 'required|string',
        ]);

        $property = Property::findOrFail($request->property_id);
        
        $amount = 1; 
        $accountReference = 'MAKAO-' . $property->id;

        try {
            DB::beginTransaction();

            $darajaResponse = $this->darajaService->stkPush(
                $request->phone_number,
                $amount,
                $accountReference
            );

            $payment = Payment::create([
                'agency_id'           => $property->agency_id ?? 1, 
                'user_id'             => Auth::user()?->id ?? 1,  
                'property_id'         => $property->id,
                'amount'              => $amount,
                'merchant_request_id' => $darajaResponse['MerchantRequestID'] ?? null,
                'checkout_request_id' => $darajaResponse['CheckoutRequestID'] ?? null,
                'status'              => 'pending',
                'metadata'            => [
                    'property_title' => $property->title ?? 'Property Rental Checkout',
                    'client_email'   => Auth::user()?->email ?? 'guest@makao.com'
                ]
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'STK Push initiated successfully.',
                'payment' => $payment,
                'daraja'  => $darajaResponse
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('M-Pesa STK Push Initiation Failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process checkout request: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Real-time Payment Status Checker for Frontend Polling
     * Route: GET /api/v1/payments/status/{checkoutRequestID}
     */
    public function checkStatus($checkoutRequestID)
    {
        $payment = Payment::where('checkout_request_id', $checkoutRequestID)->first();

        if (!$payment) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction tracking identifier not found.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'status' => $payment->status,
            'receipt_number' => $payment->receipt_number
        ], 200);
    }

    /**
     * Safaricom Webhook Callback Handler
     * Route: POST /api/v1/payments/callback
     */
    public function callback(Request $request)
    {
        Log::info('Incoming M-Pesa Callback Matrix Payload Received', $request->all());

        $callbackData = $request->json('Body.stkCallback');
        $resultCode   = $callbackData['ResultCode'] ?? null;
        $checkoutId   = $callbackData['CheckoutRequestID'] ?? null;

        $payment = Payment::where('checkout_request_id', $checkoutId)->first();

        if (!$payment) {
            Log::warning('M-Pesa Callback received for untracked checkout ID: ' . $checkoutId);
            return response()->json(['status' => 'untracked'], 404);
        }

        try {
            DB::beginTransaction();

            if ($resultCode == 0) {
                $callbackItems = $callbackData['CallbackMetadata']['Item'] ?? [];
                $receiptNumber = null;

                foreach ($callbackItems as $item) {
                    if ($item['Name'] === 'MpesaReceiptNumber') {
                        $receiptNumber = $item['Value'];
                        break;
                    }
                }

                $payment->update([
                    'receipt_number' => $receiptNumber,
                    'status'         => 'completed',
                ]);

                if ($payment->property) {
                    $payment->property->update(['status' => 'under_contract']);
                }

                Log::info("Payment Successful for Checkout ID: {$checkoutId}. Receipt: {$receiptNumber}");
            } else {
                $payment->update(['status' => 'failed']);
                Log::notice("Payment Cancelled/Failed for Checkout ID: {$checkoutId}. Code: {$resultCode}");
            }

            DB::commit();
            return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted successfully']);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error processing Daraja callback handling block: ' . $e->getMessage());
            return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Internal server error'], 500);
        }
    }
}