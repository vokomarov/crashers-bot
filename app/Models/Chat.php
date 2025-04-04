<?php

namespace App\Models;

use App\Services\OpenAIService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Chat extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'tg_id',
        'title',
        'type',
        'is_scheduled_pidar',
        'prompt',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'tg_id' => 'integer',
        'is_scheduled_pidar' => 'boolean',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'chats_users', 'chat_id', 'user_id');
    }

    public function getPrompt(): string
    {
        return $this->prompt ?? OpenAIService::PROMPT;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function pidarHistoryLogs()
    {
        return $this->hasMany(PidarHistoryLog::class, 'chat_id');
    }
}
