<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Payment;
use App\Models\User;
use App\Models\Lead;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;

class EscrowController extends Controller
{
    protected string $paystackSecret;

    public function __construct()
    {
        $this->paystackSecret = config('services.paystack.secret_key') ?? env('PAYSTACK_SECRET_KEY', '');
    }

    /**
     * GET /api/v1/escrow
     * Returns all escrow transactions for the authenticated user/agency.
     */
    public function index()
    {
        try {
            $transactions = Payment::orderBy('created_at', 'desc')->get()->map(function ($payment) {
                // Ensure metadata is an array
                $meta = $payment->metadata;
                if (is_string($meta)) {
                    $meta = json_decode($meta, true) ?? [];
                } elseif (!is_array($meta)) {
                    $meta = [];
                }

                return [
                    'id'                => $payment->id,
                    'clientName'        => $meta['client_name'] ?? 'Client Principal',
                    'client_email'      => $meta['client_email'] ?? 'client@makao.com',
                    'providerName'      => $meta['provider_name'] ?? 'Vendor Provider',
                    'provider_email'    => $meta['provider_email'] ?? 'provider@makao.com',
                    'provider_phone'    => $meta['provider_phone'] ?? '',
                    'propertyTitle'     => $meta['property_title'] ?? 'Asset Vault Transaction',
                    'amount'            => (float) $payment->amount,
                    'status'            => $payment->status,
                    'updated_at'        => $payment->updated_at?->toIso8601String() ?? now()->toIso8601String(),
                    'payment_reference' => $payment->payment_reference,
                ];
            });

            return response()->json($transactions, 200);
        } catch (\Exception $e) {
            Log::error('Escrow Index Error: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'Failed to load ledger.'], 500);
        }
    }

    /**
     * POST /api/v1/escrow
     * Creates a new escrow agreement (or additional payment for an existing one)
     * and returns a Paystack payment URL.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'clientName'    => 'nullable|string',
            'clientEmail'   => 'required|email',
            'providerName'  => 'nullable|string',
            'providerEmail' => 'required|email',
            'providerPhone' => 'nullable|string',
            'amount'        => 'required|numeric|min:1',
            'description'   => 'nullable|string',
        ]);

        if (empty($this->paystackSecret)) {
            return response()->json([
                'status'  => false,
                'message' => 'Server configuration missing: PAYSTACK_SECRET_KEY is not defined.'
            ], 500);
        }

        $amountInCents = intval($validated['amount'] * 100);
        $reference = 'MAK-ESC-' . uniqid() . '-' . time();

        $userId = Auth::id() ?? User::first()?->id ?? null;
        if (!$userId) {
            return response()->json([
                'status'  => false,
                'message' => 'No operational accounts found. Please seed or register a user first.'
            ], 500);
        }

        try {
            DB::beginTransaction();

            // Build metadata including optional escrow_id and lead_id
            $metadata = [
                'client_name'    => $validated['clientName'] ?? 'Client Principal',
                'client_email'   => $validated['clientEmail'],
                'provider_name'  => $validated['providerName'] ?? 'Vendor Provider',
                'provider_email' => $validated['providerEmail'],
                'provider_phone' => $validated['providerPhone'],
                'property_title' => $validated['description'] ?? 'Property Escrow Settlement',
                'lead_id'        => $request->input('metadata.lead_id'),
                'escrow_id'      => $request->input('metadata.escrow_id'), // for additional payments
            ];

            // Call Paystack
            $response = Http::withToken($this->paystackSecret)
                ->post('https://api.paystack.co/transaction/initialize', [
                    'email'        => $validated['clientEmail'],
                    'amount'       => $amountInCents,
                    'reference'    => $reference,
                    'callback_url' => env('PAYSTACK_CALLBACK_URL', 'http://localhost:5173/agent/escrows'),
                    'metadata'     => $metadata,
                ]);

            if ($response->failed()) {
                throw new \Exception('Paystack initialization failed: ' . $response->body());
            }

            $responseData = $response->json();

            // Create local payment record
            $payment = Payment::create([
                'agency_id'         => 1, // adjust as needed
                'user_id'           => $userId,
                'amount'            => $validated['amount'],
                'payment_reference' => $reference,
                'status'            => 'pending_payment',
                'property_id'       => $request->input('property_id') ?? null,
                'metadata'          => $metadata,
            ]);

            DB::commit();

            return response()->json([
                'status'     => true,
                'message'    => 'Escrow agreement initialized successfully.',
                'paymentUrl' => $responseData['data']['authorization_url'],
                'reference'  => $reference,
                'data'       => $payment,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Escrow Store Error: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Failed to initialize contract payment: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/v1/escrow/verify/{reference}
     * Verifies a Paystack transaction and updates the payment status to 'held'.
     */
    public function verify($reference)
    {
        if (empty($this->paystackSecret)) {
            return response()->json(['status' => false, 'message' => 'PAYSTACK_SECRET_KEY missing.'], 500);
        }

        try {
            // 1. Verify with Paystack
            $response = Http::withToken($this->paystackSecret)
                ->get("https://api.paystack.co/transaction/verify/" . urlencode($reference));

            if ($response->failed()) {
                Log::error("Paystack verify failed for {$reference}: " . $response->body());
                return response()->json(['status' => false, 'message' => 'Verification with Paystack failed.'], 400);
            }

            $data = $response->json();
            if (!isset($data['data']['status']) || $data['data']['status'] !== 'success') {
                return response()->json(['status' => false, 'message' => 'Payment not successful.'], 400);
            }

            DB::beginTransaction();

            // 2. Find the payment by payment_reference
            $payment = Payment::where('payment_reference', $reference)->first();

            if (!$payment) {
                // Fallback: create payment from Paystack data
                Log::info("Payment record not found for ref {$reference}, creating one from Paystack data.");
                $metadata = $data['data']['metadata'] ?? [];
                $payment = Payment::create([
                    'agency_id'         => 1,
                    'user_id'           => Auth::id() ?? 1,
                    'amount'            => $data['data']['amount'] / 100,
                    'payment_reference' => $reference,
                    'status'            => 'held',
                    'property_id'       => null,
                    'metadata'          => array_merge($metadata, ['paystack_verification' => $data['data']]),
                ]);
            } else {
                // Update existing payment
                $payment->update([
                    'status' => 'held',
                    'metadata' => array_merge($payment->metadata ?? [], ['paystack_verification' => $data['data']]),
                ]);
            }

            // 3. Optionally update Lead
            try {
                $leadId = $payment->metadata['lead_id'] ?? null;
                if ($leadId) {
                    $lead = Lead::find($leadId);
                    if ($lead) {
                        $lead->update([
                            'kanban_stage' => 'closed_won',
                            'value'        => $payment->amount,
                        ]);
                    }
                }
            } catch (\Exception $e) {
                Log::warning('Failed to update lead: ' . $e->getMessage());
            }

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'Escrow verified and held.',
                'data'    => $payment,
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Verification Error: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Server error: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/v1/escrow/{id}
     * Returns details for a single escrow transaction in the EscrowWithProgress format.
     */
public function show($id)
{
    try {
        // 1. Find the primary payment
        $primaryPayment = Payment::findOrFail($id);

        // 2. Determine the escrow_id from metadata (if any)
        $meta = $this->safeDecodeMetadata($primaryPayment->metadata);
        $escrowId = $meta['escrow_id'] ?? null;

        // 3. If there is an escrow_id, fetch ALL payments linked to it
        if ($escrowId) {
            $payments = Payment::where('metadata->escrow_id', $escrowId)->get();
        } else {
            // If no escrow_id, just use the single payment
            $payments = collect([$primaryPayment]);
        }

        // 4. Aggregate totals
        $totalAmount = $payments->sum('amount');
        $totalPaid = $payments->whereIn('status', ['held', 'inspection', 'released', 'completed'])->sum('amount');
        $remaining = $totalAmount - $totalPaid;
        $isFullyFunded = $remaining <= 0;

        // 5. Determine overall status (using the most advanced status among linked payments)
        $statuses = $payments->pluck('status')->toArray();
        if (in_array('held', $statuses) || in_array('inspection', $statuses)) {
            $overallStatus = 'held';
        } elseif (in_array('released', $statuses)) {
            $overallStatus = 'released';
        } elseif (in_array('completed', $statuses)) {
            $overallStatus = 'completed';
        } elseif (in_array('refunded', $statuses)) {
            $overallStatus = 'refunded';
        } else {
            $overallStatus = 'pending_payment';
        }

        // 6. Calculate progress
        $progressMap = [
            'pending_payment' => 10,
            'held'            => 50,
            'inspection'      => 70,
            'released'        => 90,
            'completed'       => 100,
            'refunded'        => 100,
        ];
        $progress = $progressMap[$overallStatus] ?? 0;

        // 7. Build the response using the primary payment's metadata (for client details)
        $clientMeta = $this->safeDecodeMetadata($primaryPayment->metadata);

        return response()->json([
            'escrow' => [
                'id'                => $primaryPayment->id,
                'clientName'        => $clientMeta['client_name'] ?? 'Client Principal',
                'client_email'      => $clientMeta['client_email'] ?? 'client@makao.com',
                'providerName'      => $clientMeta['provider_name'] ?? 'Vendor Provider',
                'provider_email'    => $clientMeta['provider_email'] ?? 'provider@makao.com',
                'provider_phone'    => $clientMeta['provider_phone'] ?? '',
                'propertyTitle'     => $clientMeta['property_title'] ?? 'Asset Vault Transaction',
                'amount'            => $totalAmount, // total of all linked payments
                'status'            => $overallStatus,
                'updated_at'        => $primaryPayment->updated_at?->toIso8601String() ?? now()->toIso8601String(),
                'payment_reference' => $primaryPayment->payment_reference,
            ],
            'progress'          => $progress,
            'total_paid'        => $totalPaid,
            'remaining'         => $remaining,
            'is_fully_funded'   => $isFullyFunded,
        ], 200);
    } catch (\Exception $e) {
        Log::error('Escrow Show Error: ' . $e->getMessage());
        return response()->json(['status' => false, 'message' => 'Escrow not found.'], 404);
    }
}

// Helper method to safely decode metadata
private function safeDecodeMetadata($metadata)
{
    if (is_string($metadata)) {
        $decoded = json_decode($metadata, true);
        return is_array($decoded) ? $decoded : [];
    }
    return is_array($metadata) ? $metadata : [];
}

    /**
     * POST /api/v1/escrow/release
     * Releases funds (placeholder – implement actual logic).
     */
    public function release(Request $request)
    {
        return response()->json(['status' => true, 'message' => 'Funds released safely to provider vectors.']);
    }

    /**
     * POST /api/v1/escrow/refund
     * Refunds funds (placeholder – implement actual logic).
     */
    public function refund(Request $request)
    {
        return response()->json(['status' => true, 'message' => 'Funds successfully rolled back to client container.']);
    }
}