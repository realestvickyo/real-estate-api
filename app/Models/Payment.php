<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'agency_id',
        'user_id',
        'property_id',
        'amount',
        'payment_reference',
        'metadata',
        'merchant_request_id',
        'checkout_request_id',
        'receipt_number',
        'status',
    ];

    protected $casts = [
        'amount'   => 'float',
        'metadata' => 'array',  // Important: automatically converts JSON to array
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}