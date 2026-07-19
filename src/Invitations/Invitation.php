<?php

namespace Martis\Invitations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An invitation issued by a privileged operator, letting a new user join
 * without self-service registration (`config('martis.invitations')`,
 * gated behind the `martis-invite` Gate). This is the persistence model
 * only — the issuing/accepting/revoking workflow lives in a later
 * manager class, not here.
 *
 * @property int $id
 * @property string $email
 * @property string $token
 * @property string $status
 * @property ?string $role
 * @property int|string|null $invited_by
 * @property int|string|null $accepted_user_id
 * @property ?Carbon $expires_at
 * @property ?Carbon $accepted_at
 * @property array<string, mixed> $metadata
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class Invitation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_EXPIRED = 'expired';

    /** @var string */
    protected $table = 'invitations';

    /** @var list<string> */
    protected $fillable = [
        'email',
        'token',
        'status',
        'role',
        'invited_by',
        'accepted_user_id',
        'expires_at',
        'accepted_at',
        'metadata',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'metadata' => 'array',
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    /**
     * The plain-text invitation token, held only in memory for the
     * request that generated it (e.g. to include in the invite
     * notification/email). It is never written to the `invitations`
     * table — only the hashed `token` column is persisted — and is not
     * declared as a fillable/cast Eloquent attribute, so Laravel never
     * tries to read or write it as a database column. Later tasks
     * (the invitation manager, the invite notification) set this
     * transiently right after generating a token.
     */
    public ?string $rawToken = null;

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
