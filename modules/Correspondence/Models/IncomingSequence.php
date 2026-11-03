<?php

namespace Modules\Correspondence\Models;

use Illuminate\Database\Eloquent\Model;

class IncomingSequence extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'correspondence_incoming_sequences';

    protected $primaryKey = 'year';

    protected $fillable = ['year', 'last_number'];
}
