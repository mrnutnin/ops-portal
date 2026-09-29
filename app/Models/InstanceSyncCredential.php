<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class InstanceSyncCredential extends Model
{
    protected $fillable = ['instance_id', 'target_url', 'key_id', 'secret', 'previous_key_id', 'previous_secret'];

    protected $hidden = ['secret', 'previous_secret'];

    protected function casts(): array
    {
        return ['secret' => 'encrypted', 'previous_secret' => 'encrypted'];
    }
}
