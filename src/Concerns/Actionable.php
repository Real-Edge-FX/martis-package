<?php

namespace Martis\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Martis\Models\ActionEvent;

/**
 * Trait for Eloquent models that can be targeted by Martis actions.
 *
 * @phpstan-require-extends Model
 *
 * Add this trait to any model to gain access to its action event history.
 *
 * Usage:
 *   use Martis\Concerns\Actionable;
 *
 *   class Post extends Model
 *   {
 *       use Actionable;
 *   }
 *
 *   // Query action history
 *   $post->actions()->latest()->get();
 *   $post->actions()->where('name', 'Publish Post')->count();
 */
trait Actionable
{
    /**
     * Get all action events for this model.
     */
    public function actions(): MorphMany
    {
        return $this->morphMany(ActionEvent::class, 'actionable');
    }
}
