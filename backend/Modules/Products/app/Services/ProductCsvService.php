<?php

namespace Modules\Products\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Products\Jobs\ProcessProductImportJob;
use Modules\Products\Models\Brand;
use Modules\Products\Models\Category;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductImport;
use Modules\Products\Models\Unit;
use Modules\Purchasing\Models\Supplier;
use RuntimeException;

class ProductCsvService
{
    public const HEADERS = [
        'name',
        'sku',
        'barcode',
        'description',
        'category',
        'brand',
        'unit',
        'supplier',
        'basePrice',
        'costPrice',
        'reorderLevel',
        'minStock',
        'maxStock',
    ];

    /**
     * @return array{rows: list<array<string, ?string>>, errors: list<array{row: int, field: string, message: string}>, validCount: int}
     */
    public function preview(UploadedFile $file, int $organizationId): array
    {
        $parsed = $this->parseFile($file);
        $errors = [];
        $validCount = 0;

        foreach ($parsed as $index => $row) {
            $rowNumber = $index + 2; // header is row 1
            $rowErrors = $this->validateRow($row, $organizationId, $rowNumber);
            if ($rowErrors === []) {
                $validCount++;
            } else {
                array_push($errors, ...$rowErrors);
            }
        }

        return [
            'rows' => $parsed,
            'errors' => $errors,
            'validCount' => $validCount,
            'totalRows' => count($parsed),
        ];
    }

    public function startImport(
        UploadedFile $file,
        int $organizationId,
        int $userId,
        int $asyncThreshold = 100,
    ): ProductImport {
        $preview = $this->preview($file, $organizationId);

        if ($preview['totalRows'] === 0) {
            throw new RuntimeException('CSV has no data rows.');
        }

        if ($preview['validCount'] === 0) {
            throw new RuntimeException('CSV has no valid rows to import.');
        }

        $path = $file->storeAs(
            'product-imports/'.$organizationId,
            Str::uuid()->toString().'.csv',
        );

        $async = $preview['totalRows'] >= $asyncThreshold;

        $import = ProductImport::query()->create([
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'status' => $async ? 'pending' : 'processing',
            'original_filename' => $file->getClientOriginalName(),
            'total_rows' => $preview['totalRows'],
            'success_count' => 0,
            'error_count' => count($preview['errors']),
            'errors' => $preview['errors'],
            'async' => $async,
            'started_at' => $async ? null : now(),
        ]);

        // stash path on disk keyed by import id
        Storage::put($this->payloadPath($import), json_encode([
            'path' => $path,
            'organizationId' => $organizationId,
        ], JSON_THROW_ON_ERROR));

        if ($async) {
            ProcessProductImportJob::dispatch($import->id);

            return $import;
        }

        $this->processImport($import);

        return $import->fresh();
    }

    public function processImport(ProductImport $import): ProductImport
    {
        $payload = json_decode((string) Storage::get($this->payloadPath($import)), true, 512, JSON_THROW_ON_ERROR);
        $absolute = Storage::path($payload['path']);
        $rows = $this->parsePath($absolute);

        $import->status = 'processing';
        $import->started_at = $import->started_at ?? now();
        $import->save();

        $success = 0;
        $errors = $import->errors ?? [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $rowErrors = $this->validateRow($row, $import->organization_id, $rowNumber);
            if ($rowErrors !== []) {
                array_push($errors, ...$rowErrors);
                continue;
            }

            try {
                $this->upsertRow($row, $import->organization_id);
                $success++;
            } catch (\Throwable $e) {
                $errors[] = [
                    'row' => $rowNumber,
                    'field' => 'sku',
                    'message' => $e->getMessage(),
                ];
            }
        }

        $import->success_count = $success;
        $import->error_count = count($errors);
        $import->errors = array_values($errors);
        $import->status = 'completed';
        $import->finished_at = now();
        $import->save();

        return $import;
    }

    public function exportCsv(int $organizationId): string
    {
        $products = Product::query()
            ->with(['category', 'brand', 'unit', 'supplier'])
            ->where('organization_id', $organizationId)
            ->orderBy('name')
            ->get();

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, self::HEADERS);

        foreach ($products as $product) {
            fputcsv($handle, [
                $product->name,
                $product->sku,
                $product->barcode,
                $product->description,
                $product->category?->name,
                $product->brand?->name,
                $product->unit?->abbreviation ?? $product->unit?->name,
                $product->supplier?->name,
                $product->base_price,
                $product->cost_price,
                $product->reorder_level,
                $product->min_stock,
                $product->max_stock,
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $csv;
    }

    private function payloadPath(ProductImport $import): string
    {
        return 'product-imports/payloads/'.$import->id.'.json';
    }

    /**
     * @return list<array<string, ?string>>
     */
    private function parseFile(UploadedFile $file): array
    {
        return $this->parsePath($file->getRealPath() ?: throw new RuntimeException('Unable to read upload.'));
    }

    /**
     * @return list<array<string, ?string>>
     */
    private function parsePath(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException('Unable to open CSV.');
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            throw new RuntimeException('CSV is empty.');
        }

        $header = array_map(fn ($h) => Str::camel(trim((string) $h)), $header);
        foreach (['name', 'sku', 'basePrice'] as $required) {
            if (! in_array($required, $header, true)) {
                fclose($handle);
                throw new RuntimeException("CSV missing required column: {$required}");
            }
        }

        $rows = [];
        while (($data = fgetcsv($handle)) !== false) {
            if ($this->rowIsEmpty($data)) {
                continue;
            }
            $row = [];
            foreach ($header as $i => $key) {
                $row[$key] = isset($data[$i]) ? trim((string) $data[$i]) : null;
                if ($row[$key] === '') {
                    $row[$key] = null;
                }
            }
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param  array<int, mixed>  $data
     */
    private function rowIsEmpty(array $data): bool
    {
        foreach ($data as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, ?string>  $row
     * @return list<array{row: int, field: string, message: string}>
     */
    private function validateRow(array $row, int $organizationId, int $rowNumber): array
    {
        $errors = [];

        if (empty($row['name'])) {
            $errors[] = ['row' => $rowNumber, 'field' => 'name', 'message' => 'Name is required.'];
        }
        if (empty($row['sku'])) {
            $errors[] = ['row' => $rowNumber, 'field' => 'sku', 'message' => 'SKU is required.'];
        }
        if (empty($row['basePrice']) || ! preg_match('/^\d+(\.\d{1,4})?$/', (string) $row['basePrice'])) {
            $errors[] = ['row' => $rowNumber, 'field' => 'basePrice', 'message' => 'basePrice must be a decimal.'];
        }

        foreach (['costPrice', 'reorderLevel', 'minStock', 'maxStock'] as $decimalField) {
            if (! empty($row[$decimalField]) && ! preg_match('/^\d+(\.\d{1,4})?$/', (string) $row[$decimalField])) {
                $errors[] = ['row' => $rowNumber, 'field' => $decimalField, 'message' => "{$decimalField} must be a decimal."];
            }
        }

        if (! empty($row['sku'])) {
            $existsAsVariant = DB::table('product_variants')
                ->where('organization_id', $organizationId)
                ->where('sku', $row['sku'])
                ->exists();
            if ($existsAsVariant) {
                $errors[] = ['row' => $rowNumber, 'field' => 'sku', 'message' => 'SKU already used by a variant.'];
            }
        }

        if (! empty($row['barcode'])) {
            $existsAsVariantBarcode = DB::table('barcodes')
                ->where('organization_id', $organizationId)
                ->where('code', $row['barcode'])
                ->exists();
            if ($existsAsVariantBarcode) {
                $errors[] = ['row' => $rowNumber, 'field' => 'barcode', 'message' => 'Barcode already used by a variant.'];
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, ?string>  $row
     */
    private function upsertRow(array $row, int $organizationId): void
    {
        DB::transaction(function () use ($row, $organizationId): void {
            $categoryId = $this->resolveCategoryId($organizationId, $row['category'] ?? null);
            $brandId = $this->resolveBrandId($organizationId, $row['brand'] ?? null);
            $unitId = $this->resolveUnitId($organizationId, $row['unit'] ?? null);
            $supplierId = $this->resolveSupplierId($organizationId, $row['supplier'] ?? null);

            $product = Product::query()->updateOrCreate(
                [
                    'organization_id' => $organizationId,
                    'sku' => $row['sku'],
                ],
                [
                    'name' => $row['name'],
                    'barcode' => $row['barcode'] ?? null,
                    'description' => $row['description'] ?? null,
                    'category_id' => $categoryId,
                    'brand_id' => $brandId,
                    'unit_id' => $unitId,
                    'supplier_id' => $supplierId,
                    'base_price' => $row['basePrice'],
                    'cost_price' => $row['costPrice'] ?? null,
                    'reorder_level' => $row['reorderLevel'] ?? null,
                    'min_stock' => $row['minStock'] ?? null,
                    'max_stock' => $row['maxStock'] ?? null,
                    'is_active' => true,
                ],
            );

            app(ProductVariantProvisioner::class)->ensureDefaultVariant($product);
        });
    }

    private function resolveCategoryId(int $organizationId, ?string $name): ?int
    {
        if ($name === null || $name === '') {
            return null;
        }

        $category = Category::query()->firstOrCreate(
            [
                'organization_id' => $organizationId,
                'slug' => Str::slug($name),
            ],
            [
                'name' => $name,
                'is_active' => true,
            ],
        );

        return $category->id;
    }

    private function resolveBrandId(int $organizationId, ?string $name): ?int
    {
        if ($name === null || $name === '') {
            return null;
        }

        $brand = Brand::query()->firstOrCreate(
            [
                'organization_id' => $organizationId,
                'slug' => Str::slug($name),
            ],
            [
                'name' => $name,
                'is_active' => true,
            ],
        );

        return $brand->id;
    }

    private function resolveUnitId(int $organizationId, ?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $unit = Unit::query()
            ->where('organization_id', $organizationId)
            ->where(function ($q) use ($value): void {
                $q->where('abbreviation', $value)->orWhere('name', $value);
            })
            ->first();

        if ($unit !== null) {
            return $unit->id;
        }

        return Unit::query()->create([
            'organization_id' => $organizationId,
            'name' => $value,
            'abbreviation' => Str::limit($value, 20, ''),
            'is_active' => true,
        ])->id;
    }

    private function resolveSupplierId(int $organizationId, ?string $name): ?int
    {
        if ($name === null || $name === '') {
            return null;
        }

        $supplier = Supplier::query()->firstOrCreate(
            [
                'organization_id' => $organizationId,
                'name' => $name,
            ],
            [
                'code' => Str::upper(Str::limit(Str::slug($name, ''), 50, '')),
                'is_active' => true,
            ],
        );

        return $supplier->id;
    }
}
