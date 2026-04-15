<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SmsLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'phone_number',
        'message_content',
        'sms_status',
        'sent_at',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}