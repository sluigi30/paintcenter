<?php

namespace App\Models;

use App\Models\Concerns\TracksActivity;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements FilamentUser
{
    use HasApiTokens, HasFactory, Notifiable, TracksActivity;

    public function activityTitle(): string
    {
        return $this->name . ' (' . $this->role . ')';
    }

    protected $fillable = [
        'role',
        'is_archived',
        'first_name',
        'last_name',
        'phone',
        'email',
        'password',
        'address',
    ];

    protected $appends = ['name'];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    // Relationships
    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function sentMessages()
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    public function receivedMessages()
    {
        return $this->hasMany(Message::class, 'receiver_id');
    }
    public function canAccessPanel(Panel $panel): bool
    {
        return ($this->isAdmin() || $this->isSuperAdmin()) && !$this->is_archived;
    }

    /** Every admin-side account, archived ones included. */
    public function scopeAdmins($query)
    {
        return $query->whereIn('role', ['admin', 'super_admin']);
    }

    public function scopeCustomers($query)
    {
        return $query->where('role', 'customer');
    }

    /**
     * The account the store speaks through — used as the sender for automated
     * order messages and as the reply-to the mobile app is handed. Archived
     * admins must never receive or send new messages.
     */
    public static function activeAdmin(): ?self
    {
        return static::query()->admins()->where('is_archived', false)->orderBy('id')->first();
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }
    public function getNameAttribute(): string
    {
        return $this->first_name . ' ' . $this->last_name;
    }
    public function cartItems()
    {
        return $this->hasMany(CartItem::class);
    }
}