<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServerOutbound extends Model
{
    protected $table = 'v2_server_outbound';
    protected $fillable = ['name', 'type', 'settings', 'notes'];
    protected $hidden = ['settings'];
    protected $casts = ['settings' => 'encrypted:array'];

    public function servers(): HasMany
    {
        return $this->hasMany(Server::class, 'outbound_id');
    }

    public function tag(): string
    {
        return 'sx-outbound-' . $this->id;
    }
}
