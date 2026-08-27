<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('title')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index(['organization_id', 'supplier_id']);
        });

        Schema::create('supplier_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->string('type', 32)->default('other'); // billing|shipping|other
            $table->string('line1');
            $table->string('line2')->nullable();
            $table->string('city')->nullable();
            $table->string('region')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['organization_id', 'supplier_id']);
        });

        Schema::create('supplier_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('supplier_sku')->nullable();
            $table->decimal('cost', 15, 4)->nullable();
            $table->timestamps();

            $table->unique(['supplier_id', 'product_id']);
            $table->index(['organization_id', 'product_id']);
        });

        Schema::create('supplier_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32)->default('pending'); // pending|processing|completed|failed
            $table->string('original_filename')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->json('errors')->nullable();
            $table->boolean('async')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_imports');
        Schema::dropIfExists('supplier_products');
        Schema::dropIfExists('supplier_addresses');
        Schema::dropIfExists('supplier_contacts');
    }
};
