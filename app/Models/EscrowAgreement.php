<?php
// 📁 File: app/Models/EscrowAgreement.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EscrowAgreement extends Model
{
    use HasFactory;

    protected $fillable = [
        'lead_id',
        'property_id',
        'client_name',
        'client_email',
        'total_amount',
        'status',
        'current_milestone_index',
        'paystack_reference',
        'metadata'
    ];

    // Ensure metadata is converted from a raw JSON string to a clean PHP array automatically
    protected $casts = [
        'metadata' => 'array',
    ];

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function property()
    {
        return $this->belongsTo(Property::class);
    }
}