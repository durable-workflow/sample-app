<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiWorkflowMessage extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $primaryKey = 'reference';

    protected $guarded = [];
}
