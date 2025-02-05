<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $fillable = [
        'message_id',
        'sender',
        'recipient',
        'content',
        'status',
        'operator_id'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_QUEUED = 'queued';
    const STATUS_SENDING = 'sending';
    const STATUS_SENT = 'sent';
    const STATUS_DELIVERED = 'delivered';
    const STATUS_FAILED = 'failed';

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    public function queue()
    {
        return $this->hasOne(MessageQueue::class, 'message_id', 'message_id');
    }

    public function markAsSent()
    {
        $this->status = self::STATUS_SENT;
        $this->save();
    }

    public function markAsDelivered()
    {
        $this->status = self::STATUS_DELIVERED;
        $this->save();
    }

    public function markAsFailed(string $error = null)
    {
        $this->status = self::STATUS_FAILED;
        if ($error) {
            $this->error_message = $error;
        }
        $this->save();
    }

    public static function generateMessageId(): string
    {
        return uniqid('MSG_', true);
    }

    public function toArray()
    {
        $array = parent::toArray();
        $array['operator'] = $this->operator ? $this->operator->name : null;
        return $array;
    }
} 