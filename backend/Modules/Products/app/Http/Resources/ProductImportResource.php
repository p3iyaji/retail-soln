<?php

namespace Modules\Products\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Modules\Products\Models\ProductImport */
class ProductImportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'originalFilename' => $this->original_filename,
            'totalRows' => $this->total_rows,
            'successCount' => $this->success_count,
            'errorCount' => $this->error_count,
            'errors' => $this->errors ?? [],
            'async' => $this->async,
            'startedAt' => $this->started_at?->toIso8601String(),
            'finishedAt' => $this->finished_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
