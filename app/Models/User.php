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
        return static::query()
            ->admins()
            ->where('is_archived', false)
            // An invited-but-unclaimed account is not a person yet. Letting one
            // be picked here would have order updates arrive from an admin who
            // has never signed in - and, because the inbox is built from
            // existing messages, park those threads under an account nobody
            // reads. Reachable in practice as soon as older admins are archived.
            ->whereDoesntHave('adminInvite', fn ($q) => $q->unaccepted())
            ->orderBy('id')
            ->first();
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

    /** The invitation that made this account usable, if it was created by one. */
    public function adminInvite()
    {
        return $this->hasOne(AdminInvite::class);
    }

    /**
     * Created, emailed an invitation, never claimed. The account exists and is
     * not archived, but nobody has ever set a password on it or signed in.
     */
    public function hasPendingInvite(): bool
    {
        return $this->adminInvite !== null && ! $this->adminInvite->isAccepted();
    }

    /**
     * Send a password-reset link ONLY to an admin who could actually use one.
     *
     * `users` holds customers too, so without this a customer's address typed
     * into the panel's forgot-password form would be emailed an admin-panel
     * reset link, and a deactivated admin could quietly walk their own password
     * back. Silently doing nothing (rather than erroring) keeps the broker's
     * response identical either way, so the form cannot be used to find out
     * which addresses belong to admins.
     *
     * Customers have no recovery path at all yet - that is a known gap, noted
     * in CLAUDE.md, and deliberately not solved here.
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        if ($this->is_archived || ! ($this->isAdmin() || $this->isSuperAdmin())) {
            return;
        }

        parent::sendPasswordResetNotification($token);
    }
}