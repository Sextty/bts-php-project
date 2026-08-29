<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AsyncOutboxEvent extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_RETRYING = 'retrying';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'type', 'aggregate_type', 'aggregate_id', 'payload', 'dedupe_key', 'status',
        'attempts', 'available_at', 'started_at', 'processed_at', 'failed_at',
        'queue_delay_ms', 'runtime_ms', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'started_at' => 'datetime',
            'processed_at' => 'datetime',
            'failed_at' => 'datetime',
            'queue_delay_ms' => 'integer',
            'runtime_ms' => 'integer',
        ];
    }
}
