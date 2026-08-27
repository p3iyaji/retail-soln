<?php

namespace Modules\Purchasing\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Products\Models\Product;
use Modules\Purchasing\Jobs\ProcessSupplierImportJob;
use Modules\Purchasing\Models\Supplier;
use Modules\Purchasing\Models\SupplierImport;
use RuntimeException;

class SupplierCsvService
{
    public const HEADERS = [
        'name',
        'code',
        'isActive',
        'contactName',
        'contactEmail',
        'contactPhone',
        'addressType',
        'line1',
        'line2',
        'city',
        'region',
        'postalCode',
        'country',
        'productSku',
        'supplierSku',
        'cost',
    ];

    /**
     * @return array{rows: list<array<string, ?string>>, errors: list<array{row: int, field: string, message: string}>, validCount: int, totalRows: int}
     */
    public function preview(UploadedFile $file, int $organizationId): array
    {
        $parsed = $this->parseFile($file);
        $errors = [];
        $validCount = 0;

        foreach ($parsed as $index => $row) {
            $rowNumber = $index + 2;
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
    ): SupplierImport {
        $preview = $this->preview($file, $organizationId);

        if ($preview['totalRows'] === 0) {
            throw new RuntimeException('CSV has no data rows.');
        }

        if ($preview['validCount'] === 0) {
            throw new RuntimeException('CSV has no valid rows to import.');
        }

        $path = $file->storeAs(
            'supplier-imports/'.$organizationId,
            Str::uuid()->toString().'.csv',
        );

        $async = $preview['totalRows'] >= $asyncThreshold;

        $import = SupplierImport::query()->create([
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

        Storage::put($this->payloadPath($import), json_encode([
            'path' => $path,
            'organizationId' => $organizationId,
        ], JSON_THROW_ON_ERROR));

        if ($async) {
            ProcessSupplierImportJob::dispatch($import->id);

            return $import;
        }

        $this->processImport($import);

        return $import->fresh();
    }

    public function processImport(SupplierImport $import): SupplierImport
    {
        try {
            $payload = json_decode((string) Storage::get($this->payloadPath($import)), true, 512, JSON_THROW_ON_ERROR);
            $absolute = Storage::path($payload['path']);
            $rows = $this->parsePath($absolute);

            $import->status = 'processing';
            $import->started_at = $import->started_at ?? now();
            $import->save();

            $success = 0;
            $errors = [];

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
                        'field' => 'name',
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
        } catch (\Throwable $e) {
            $import->status = 'failed';
            $import->errors = array_merge($import->errors ?? [], [[
                'row' => 0,
                'field' => 'import',
                'message' => $e->getMessage(),
            ]]);
            $import->finished_at = now();
            $import->save();

            throw $e;
        }
    }

    public function exportCsv(int $organizationId): string
    {
        $suppliers = Supplier::query()
            ->with(['contacts', 'addresses', 'productMappings.product'])
            ->where('organization_id', $organizationId)
            ->orderBy('name')
            ->get();

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, self::HEADERS);

        foreach ($suppliers as $supplier) {
            $contact = $supplier->contacts->firstWhere('is_primary', true) ?? $supplier->contacts->first();
            $address = $supplier->addresses->firstWhere('is_default', true) ?? $supplier->addresses->first();
            $mappings = $supplier->productMappings;

            if ($mappings->isEmpty()) {
                fputcsv($handle, $this->csvRow($supplier, $contact, $address, null));
                continue;
            }

            foreach ($mappings as $mapping) {
                fputcsv($handle, $this->csvRow($supplier, $contact, $address, $mapping));
            }
        }

        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $csv;
    }

    /**
     * @return list<mixed>
     */
    private function csvRow(
        Supplier $supplier,
        mixed $contact,
        mixed $address,
        mixed $mapping,
    ): array {
        return [
            $supplier->name,
            $supplier->code,
            $supplier->is_active ? '1' : '0',
            $contact?->name,
            $contact?->email,
            $contact?->phone,
            $address?->type,
            $address?->line1,
            $address?->line2,
            $address?->city,
            $address?->region,
            $address?->postal_code,
            $address?->country,
            $mapping?->product?->sku,
            $mapping?->supplier_sku,
            $mapping?->cost,
        ];
    }

    private function payloadPath(SupplierImport $import): string
    {
        return 'supplier-imports/payloads/'.$import->id.'.json';
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
        if (! in_array('name', $header, true)) {
            fclose($handle);
            throw new RuntimeException('CSV missing required column: name');
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

        if (! empty($row['contactEmail']) && ! filter_var($row['contactEmail'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = ['row' => $rowNumber, 'field' => 'contactEmail', 'message' => 'contactEmail must be a valid email.'];
        }

        if (! empty($row['cost']) && ! preg_match('/^\d+(\.\d{1,4})?$/', (string) $row['cost'])) {
            $errors[] = ['row' => $rowNumber, 'field' => 'cost', 'message' => 'cost must be a decimal.'];
        }

        if (! empty($row['addressType']) && ! in_array($row['addressType'], ['billing', 'shipping', 'other'], true)) {
            $errors[] = ['row' => $rowNumber, 'field' => 'addressType', 'message' => 'addressType must be billing, shipping, or other.'];
        }

        $hasAddressExtras = ! empty($row['city'])
            || ! empty($row['country'])
            || ! empty($row['addressType'])
            || ! empty($row['line2'])
            || ! empty($row['region'])
            || ! empty($row['postalCode']);

        if (empty($row['line1']) && $hasAddressExtras) {
            $errors[] = ['row' => $rowNumber, 'field' => 'line1', 'message' => 'line1 is required when address fields are present.'];
        }

        if (! empty($row['productSku'])) {
            $exists = Product::query()
                ->where('organization_id', $organizationId)
                ->where('sku', $row['productSku'])
                ->exists();
            if (! $exists) {
                $errors[] = ['row' => $rowNumber, 'field' => 'productSku', 'message' => 'Unknown product SKU.'];
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, ?string>  $row
     */
    private function upsertRow(array $row, int $organizationId): void
    {
        $isActive = true;
        if (isset($row['isActive'])) {
            $isActive = in_array(Str::lower((string) $row['isActive']), ['1', 'true', 'yes', 'y'], true);
        }

        $lookup = ['organization_id' => $organizationId];
        if (! empty($row['code'])) {
            $lookup['code'] = $row['code'];
        } else {
            $lookup['name'] = $row['name'];
        }

        $supplier = Supplier::query()->updateOrCreate(
            $lookup,
            [
                'name' => $row['name'],
                'code' => $row['code'] ?? null,
                'is_active' => $isActive,
            ],
        );

        if (! empty($row['contactName'])) {
            $existing = $supplier->contacts()
                ->where('name', $row['contactName'])
                ->first();
            if ($existing === null) {
                $supplier->contacts()->create([
                    'organization_id' => $organizationId,
                    'name' => $row['contactName'],
                    'email' => $row['contactEmail'] ?? null,
                    'phone' => $row['contactPhone'] ?? null,
                    'is_primary' => ! $supplier->contacts()->exists(),
                ]);
            } else {
                $existing->update([
                    'email' => $row['contactEmail'] ?? $existing->email,
                    'phone' => $row['contactPhone'] ?? $existing->phone,
                ]);
            }
        }

        if (! empty($row['line1'])) {
            $existingAddress = $supplier->addresses()
                ->where('line1', $row['line1'])
                ->first();

            if ($existingAddress === null) {
                $supplier->addresses()->create([
                    'organization_id' => $organizationId,
                    'type' => $row['addressType'] ?? 'other',
                    'line1' => $row['line1'],
                    'line2' => $row['line2'] ?? null,
                    'city' => $row['city'] ?? null,
                    'region' => $row['region'] ?? null,
                    'postal_code' => $row['postalCode'] ?? null,
                    'country' => $row['country'] ?? null,
                    'is_default' => ! $supplier->addresses()->exists(),
                ]);
            } else {
                $update = [
                    'line1' => $row['line1'],
                ];
                if (! empty($row['addressType'])) {
                    $update['type'] = $row['addressType'];
                }
                if (array_key_exists('line2', $row) && $row['line2'] !== null) {
                    $update['line2'] = $row['line2'];
                }
                if (array_key_exists('city', $row) && $row['city'] !== null) {
                    $update['city'] = $row['city'];
                }
                if (array_key_exists('region', $row) && $row['region'] !== null) {
                    $update['region'] = $row['region'];
                }
                if (array_key_exists('postalCode', $row) && $row['postalCode'] !== null) {
                    $update['postal_code'] = $row['postalCode'];
                }
                if (array_key_exists('country', $row) && $row['country'] !== null) {
                    $update['country'] = $row['country'];
                }
                // Preserve is_default on re-import; do not clear when addressType omitted.
                $existingAddress->update($update);
            }
        }

        if (! empty($row['productSku'])) {
            $product = Product::query()
                ->where('organization_id', $organizationId)
                ->where('sku', $row['productSku'])
                ->firstOrFail();

            $supplier->productMappings()->updateOrCreate(
                [
                    'organization_id' => $organizationId,
                    'product_id' => $product->id,
                ],
                [
                    'supplier_sku' => $row['supplierSku'] ?? null,
                    'cost' => $row['cost'] ?? null,
                ],
            );
        }
    }
}
