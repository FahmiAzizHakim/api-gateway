<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\UserMenuGroup;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'website_id',
        'name',
        'email',
        'password',
        'roles_code',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /**
     * What the services read off the token.
     *
     * The gateway is the only holder of the users table, so a service learns
     * who is calling and which website they may touch from these claims alone
     * -- see the request-context helpers in each service's app/Helpers.
     */
    public function getJWTCustomClaims()
    {
        return [
            'website_id' => $this->website_id,
            'email'      => $this->email,
            'name'       => $this->name,
            'roles_code' => $this->roles_code,
        ];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function menuGroup(): BelongsTo
    {
        return $this->belongsTo(
            UserMenuGroup::class,
            'roles_code', // FK on users table
            'code'        // PK (or unique) on users_menugroup
        );
    }
}
