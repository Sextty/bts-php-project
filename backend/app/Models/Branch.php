<?php

namespace App\Models;

use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    public const DEFAULT_SLOTS = [
        '09:00:00',
        '11:00:00',
        '14:00:00',
        '15:00:00',
    ];

    /** @use HasFactory<BranchFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'ville',
        'delegation',
        'address',
        'phone',
        'fax',
        'opening_hours',
        'latitude',
        'longitude',
        'daily_capacity',
        'slot_start_time',
        'slot_end_time',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'daily_capacity' => 'integer',
            'is_default' => 'boolean',
        ];
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * A plain search-URL link, not an embedded map — Google Maps Embed needs a billed API key,
     * this needs neither an key nor billing and works in any browser tab.
     */
    public function googleMapsUrl(): string
    {
        return "https://www.google.com/maps/search/?api=1&query={$this->latitude},{$this->longitude}";
    }

    /** How many slots the branch offers per day, derived from standard slots or its own hours/capacity. */
    public function slotTimes(): array
    {
        if ($this->daily_capacity === 4) {
            return self::DEFAULT_SLOTS;
        }

        $start = \Carbon\Carbon::parse($this->slot_start_time ?? '09:00:00');
        $windowMinutes = \Carbon\Carbon::parse($this->slot_end_time ?? '16:00:00')->diffInMinutes($start);
        $intervalMinutes = (int) ($windowMinutes / max($this->daily_capacity, 1));

        return collect(range(0, $this->daily_capacity - 1))
            ->map(fn ($i) => $start->copy()->addMinutes($i * $intervalMinutes)->format('H:i:s'))
            ->all();
    }
}
