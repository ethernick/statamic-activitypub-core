<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Models;

use Illuminate\Database\Eloquent\Model;

class AutoBlock extends Model
{
    protected $table = 'activity_pub_auto_blocks';

    protected $fillable = [
        'identifier',
        'urls',
        'reason',
    ];

    protected $casts = [
        'urls' => 'array',
    ];
}
