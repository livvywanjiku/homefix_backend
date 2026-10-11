<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A photo attached to a service request (spec §8).
 *
 * `path` is fillable, as it is on `ProfessionalDocument`, because the controller
 * computes it and hands it to `create()`. The protection is that the controller
 * derives it from the uploaded file — a client cannot choose where its file
 * lands, and no request field is ever read into this column.
 */
#[Fillable(['service_request_id', 'path', 'original_name', 'size_bytes', 'sort_order'])]
class ServiceRequestImage extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'size_bytes' => 'integer',
        ];
    }

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }
}
