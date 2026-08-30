<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Authentication\Database\Seeders\PermissionMatrixSeeder;
use Tests\TestCase;

class ProductImageUploadApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionMatrixSeeder::class);
        Storage::fake('public');
    }

    public function test_inventory_manager_can_upload_catalog_image(): void
    {
        Sanctum::actingAs(User::factory()->inventoryManager()->create(), ['staff']);

        $file = UploadedFile::fake()->image('variant-red.jpg', 400, 400);

        $response = $this->post('/api/v1/products/images/upload', [
            'image' => $file,
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['data' => ['path', 'url']]);

        $path = $response->json('data.path');
        $this->assertIsString($path);
        $this->assertStringStartsWith('catalog/', $path);
        Storage::disk('public')->assertExists($path);
        $this->assertStringStartsWith('/storage/', $response->json('data.url'));
    }

    public function test_cashier_cannot_upload_catalog_image(): void
    {
        Sanctum::actingAs(User::factory()->cashier()->create(), ['staff']);

        $file = UploadedFile::fake()->image('denied.jpg');

        $this->post('/api/v1/products/images/upload', [
            'image' => $file,
        ], [
            'Accept' => 'application/json',
        ])->assertForbidden();
    }

    public function test_upload_rejects_non_image_files(): void
    {
        Sanctum::actingAs(User::factory()->inventoryManager()->create(), ['staff']);

        $file = UploadedFile::fake()->create('notes.txt', 10, 'text/plain');

        $this->post('/api/v1/products/images/upload', [
            'image' => $file,
        ], [
            'Accept' => 'application/json',
        ])->assertStatus(422);
    }
}
