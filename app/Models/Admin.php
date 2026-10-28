<?php

namespace App\Models;

use App\Enums\AdminRole;
use App\Notifications\AdminPasswordResetNotification;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use NotificationChannels\WebPush\HasPushSubscriptions;

class Admin extends Authenticatable
{
    use HasPushSubscriptions, Notifiable;

    protected $guarded = [];

    /**
     * The values a brand-new account starts with.
     *
     * The role default lives here as well as on the column, so that an
     * account created without one is an owner from the moment it is
     * built — not only once the database has handed the default back.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => AdminRole::Owner->value,
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * The role this admin holds, as an enum.
     *
     * The column is a plain string, but it is never handed to the
     * application as one: the accessor resolves it against
     * App\Enums\AdminRole and falls back to the least-privileged role
     * for a value it does not recognise, so a row that arrives from an
     * import can never inherit the owner's powers by accident.
     */
    protected function role(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): AdminRole => AdminRole::fromStored($value),
        );
    }

    /**
     * Whether this admin may open the given module.
     */
    public function canView(string $module): bool
    {
        return $this->role->canView($module);
    }

    /**
     * Whether this admin may change the given module's rows.
     */
    public function canManage(string $module): bool
    {
        return $this->role->canManage($module);
    }

    /**
     * Send the password reset notification.
     *
     * The framework's own notification points its link at the store front's
     * `password.reset` route. The back office resets through the admin
     * notification instead, so the link lands on the admin flow and the
     * `admins` broker's token table.
     */
    public function sendPasswordResetNotification(mixed $token): void
    {
        $this->notify(new AdminPasswordResetNotification($token));
    }
}
