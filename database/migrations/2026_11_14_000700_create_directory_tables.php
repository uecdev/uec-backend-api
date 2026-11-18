<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('title', 200);
            $table->date('start_date')->index();
            $table->date('end_date')->nullable();
            $table->string('start_time', 5)->nullable(); // HH:MM, Africa/Lagos; null = all day
            $table->text('description')->nullable();
            $table->string('location', 200)->nullable();
            $table->string('category', 40)->nullable();
            $table->string('share_url', 500)->nullable();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 160);
            $table->string('address', 255);
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('phone', 30)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('name');
            $table->index(['state', 'city']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // Trigram index keeps ILIKE '%term%' branch search fast as the directory grows.
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
            DB::statement("CREATE INDEX branches_search_trgm ON branches USING gin ((name || ' ' || address || ' ' || coalesce(city, '') || ' ' || coalesce(state, '')) gin_trgm_ops)");
        }

        Schema::create('pages', function (Blueprint $table) {
            $table->string('slug', 40)->primary();
            $table->string('title', 160);
            $table->text('body');
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('events');
    }
};
