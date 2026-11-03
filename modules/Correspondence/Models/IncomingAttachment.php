<?php

namespace Modules\Correspondence\Models;

use Illuminate\Database\Eloquent\Model;

class IncomingAttachment extends Model
{
    protected $table = 'correspondence_incoming_attachments';

    protected $fillable = ['name', 'path', 'original_name', 'mime', 'size', 'sort'];
}
