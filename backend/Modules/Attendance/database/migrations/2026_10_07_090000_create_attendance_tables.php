<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clock_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('address')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('radius_meters')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('clock_location_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('clock_locations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('hourly_rate', 15, 4);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['location_id', 'user_id']);
        });

        Schema::create('clock_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('clock_locations')->restrictOnDelete();
            $table->decimal('hourly_rate', 15, 4);
            $table->timestamp('clock_in_at');
            $table->timestamp('clock_out_at')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->decimal('gross_pay', 15, 4)->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'clock_out_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clock_shifts');
        Schema::dropIfExists('clock_location_assignments');
        Schema::dropIfExists('clock_locations');
    }
};
