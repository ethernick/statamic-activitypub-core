<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Models;

use Illuminate\Database\Eloquent\Model;

class BlockListEntry extends Model
{
    protected $table = 'activity_pub_blocklist_entries';

    protected $fillable = [
        'identifier',
    ];
}
