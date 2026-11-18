<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 40)->index();
            $table->text('message');
            $table->boolean('contact_consent')->default(false);
            $table->string('app_version', 20)->nullable();
            $table->string('platform', 10)->nullable();
            $table->string('locale', 5)->nullable();
            $table->string('status', 12)->default('new')->index(); // new | in_review | resolved | archived
            $table->text('admin_notes')->nullable();
            $table->foreignUlid('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });

        Schema::create('feedback_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('feedback_id')->constrained('feedback')->cascadeOnDelete();
            $table->string('disk', 20);
            $table->string('path', 255);
            $table->string('original_name', 255)->nullable();
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_attachments');
        Schema::dropIfExists('feedback');
    }
};
