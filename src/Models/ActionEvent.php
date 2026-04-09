<?php

namespace Martis\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Eloquent model for the action_events audit log.
 *
 * @method static ActionEvent create(array<string, mixed> $attributes = [])
 * @method static \Illuminate\Database\Eloquent\Builder<ActionEvent> where(string $column, mixed $operator = null, mixed $value = null)
 * @method static \Illuminate\Database\Eloquent\Builder<ActionEvent> query()
 *
 * Every action execution in Martis is logged to this table. Records track
 * which user ran which action, on which models, and the outcome.
 *
 * @property int $id
 * @property string $batch_id
 * @property int|null $user_id
 * @property string $name
 * @property string|null $actionable_type
 * @property int|null $actionable_id
 * @property string|null $target_type
 * @property int|null $target_id
 * @property string|null $model_type
 * @property int|null $model_id
 * @property array<string, mixed> $fields
 * @property string $status
 * @property string $exception
 * @property array<string, mixed> $original
 * @property array<string, mixed> $changes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ActionEvent extends Model
{
    /** @var string */
    protected $table = 'action_events';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'original' => 'array',
            'changes' => 'array',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * The user who triggered the action.
     *
     * Uses the configurable auth user model from Laravel.
     *
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model', 'App\Models\User');

        return $this->belongsTo($userModel, 'user_id');
    }

    /**
     * The model the action was executed on (polymorphic).
     *
     * @return MorphTo<Model, $this>
     */
    public function actionable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The target model for the action (polymorphic).
     *
     * @return MorphTo<Model, $this>
     */
    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    // -------------------------------------------------------------------------
    // Query scopes
    // -------------------------------------------------------------------------

    /**
     * Scope to a specific batch.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForBatch($query, string $batchId)
    {
        return $query->where('batch_id', $batchId);
    }

    /**
     * Scope to a specific action name.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForAction($query, string $name)
    {
        return $query->where('name', $name);
    }

    /**
     * Scope to a specific user.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForUser($query, int|string $userId)
    {
        return $query->where('user_id', $userId);
    }
}
