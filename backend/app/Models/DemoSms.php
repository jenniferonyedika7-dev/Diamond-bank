<?php

namespace App\Models;

use App\Support\TemporaryPassword;
use Illuminate\Database\Eloquent\Model;

/**
 * A simulated SMS (App\Services\SmsSender). The message is encrypted at rest
 * with APP_KEY because it can hold a temporary password; only the local-only
 * demo outbox shows it, and only while that password is still usable.
 */
class DemoSms extends Model
{
    protected $table = 'demo_sms_outbox';

    protected $primaryKey = 'demo_sms_id';

    public $timestamps = false;

    public const TEMPORARY_PASSWORD = 'TEMPORARY_PASSWORD';

    protected $fillable = ['user_id', 'purpose', 'phone', 'message', 'sent_at'];

    protected function casts(): array
    {
        return [
            'message' => 'encrypted',
            'sent_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    /** The user changed their temporary password: its texts are spent. */
    public static function markTemporaryPasswordUsed(int $userId): void
    {
        self::query()->where('user_id', $userId)->where('purpose', self::TEMPORARY_PASSWORD)->whereNull('used_at')->update(['used_at' => now()]);
    }

    /** $text with any temporary password code replaced by bullets. */
    public static function maskCode(string $text): string
    {
        return preg_replace('/(temporary password is )'.TemporaryPassword::pattern().'/', '$1'.str_repeat('•', TemporaryPassword::LENGTH), $text);
    }
}
