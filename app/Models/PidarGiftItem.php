<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PidarGiftItem extends Model
{
    use HasFactory;

    protected $table = 'pidar_gifts_items';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'pidar_gift_id',
        'title',
        'notification_at',
        'is_notified',
        'message',
        'unblocking_at',
        'is_unblocked',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'notification_at' => 'datetime',
        'is_notified' => 'boolean',
        'unblocking_at' => 'datetime',
        'is_unblocked' => 'boolean',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function pidarGift()
    {
        return $this->belongsTo(PidarGift::class, 'pidar_gift_id');
    }
}
