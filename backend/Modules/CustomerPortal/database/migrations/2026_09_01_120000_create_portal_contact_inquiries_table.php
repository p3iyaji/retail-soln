<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_contact_inquiries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_account_id')->nullable()->constrained('customer_accounts')->nullOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('phone', 64)->nullable();
            $table->string('subject');
            $table->text('message');
            $table->string('status', 32)->default('new');
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['customer_account_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_contact_inquiries');
    }
};
