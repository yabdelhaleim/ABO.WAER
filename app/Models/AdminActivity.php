<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One recorded action by a member of the back office.
 *
 * The row answers the question "who changed what, and when". It is
 * written by App\Services\Admin\AdminActivityService, which every
 * mutating admin controller calls — the record is a side effect of the
 * change, not something an operator has to remember to make.
 *
 * @property int $id
 * @property int|null $admin_id
 * @property string $action
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property array<string, mixed>|null $meta
 * @property string|null $ip
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AdminActivity extends Model
{
    use HasFactory;

    /**
     * The table name, spelled out.
     *
     * The trail is a single log rather than a catalogue of logs, so the
     * table keeps the singular name it was created under instead of
     * the pluralised one the convention would give it.
     */
    protected $table = 'admin_activity';

    protected $fillable = [
        'admin_id',
        'action',
        'subject_type',
        'subject_id',
        'meta',
        'ip',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    /**
     * The admin who performed the action.
     *
     * @return BelongsTo<Admin, AdminActivity>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
