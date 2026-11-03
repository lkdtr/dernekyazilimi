<?php

namespace Modules\Correspondence\Models;

use App\Models\Concerns\Auditable;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A document received by the association, registered with its own number.
 */
class IncomingDocument extends Model
{
    use Auditable, SoftDeletes;

    public const REGISTERED = 'registered';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [
        self::REGISTERED => 'Kayıtlı',
        self::CANCELLED => 'İptal edildi',
    ];

    /** Received as an e-Yazışma package. */
    public const EYP = 'eyp';

    /** Received as a PDF only. */
    public const PDF = 'pdf';

    public const CHANNELS = [
        'kep' => 'KEP',
        'email' => 'E-posta',
        'hand' => 'Elden / posta',
        'other' => 'Diğer',
    ];

    /** Files of the incoming documents on the private disk. */
    public const DIRECTORY = 'correspondence/gelen';

    protected $table = 'correspondence_incoming';

    protected $casts = [
        'received_on' => 'date',
        'document_date' => 'date',
        'findings' => 'array',
        'cancelled_at' => 'datetime',
    ];

    public function attachments(): HasMany
    {
        return $this->hasMany(IncomingAttachment::class, 'incoming_id')->orderBy('sort')->orderBy('id');
    }

    public function letter(): BelongsTo
    {
        return $this->belongsTo(Letter::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPackage(): bool
    {
        return $this->source === self::EYP;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::CANCELLED;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /** The letter to read: the main letter of the package, or the PDF itself. */
    public function letterPath(): string
    {
        return $this->letter_path ?? $this->file_path;
    }

    public function directory(): string
    {
        return self::DIRECTORY.'/'.$this->number_year.'/'.$this->number;
    }

    public function auditLabel(): string
    {
        return trim($this->registry_no.' '.$this->subject);
    }
}
