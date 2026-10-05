<?php

namespace App\Http\Resources;

use App\Models\ProfessionalDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProfessionalDocument
 */
class ProfessionalDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        // Reviewers and the owner may see a document; nobody else may. The URL
        // is withheld rather than the row hidden, so a rejected document still
        // appears to its owner with the reviewer's reason.
        $mayViewFile = $viewer !== null
            && ($viewer->isAdmin() || $viewer->id === $this->professionalProfile?->user_id);

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'notes' => $this->notes,
            'url' => $this->when(
                $mayViewFile && $this->path !== null,
                fn (): string => url('storage/'.ltrim($this->path, '/')),
            ),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
