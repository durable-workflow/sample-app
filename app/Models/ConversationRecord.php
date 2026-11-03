<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * External payload storage for the conversation reference sample.
 *
 * Workflow history carries only the reference string; the reply or action
 * receipt lives here, written by an activity keyed by that reference.
 */
class ConversationRecord extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $primaryKey = 'reference';

    protected $guarded = [];

    protected $casts = [
        'turn' => 'integer',
    ];
}
