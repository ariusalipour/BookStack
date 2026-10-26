<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('structured_databases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('book_id')->unique();
            $table->string('book_type', 10)->default('book');
            $table->string('name');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->foreign(['book_id', 'book_type'])->references(['id', 'type'])->on('entities')->cascadeOnDelete();
        });

        Schema::create('structured_fields', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('database_id');
            $table->string('name');
            $table->string('type', 32);
            $table->json('config');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->foreign('database_id')->references('id')->on('structured_databases')->cascadeOnDelete();
            $table->unique(['database_id', 'name']);
        });

        Schema::create('structured_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('database_id');
            $table->unsignedBigInteger('page_id')->unique();
            $table->string('page_type', 10)->default('page');
            $table->json('values');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->foreign('database_id')->references('id')->on('structured_databases')->cascadeOnDelete();
            $table->foreign(['page_id', 'page_type'])->references(['id', 'type'])->on('entities')->cascadeOnDelete();
        });

        Schema::create('structured_relations', function (Blueprint $table) {
            $table->uuid('source_record_id');
            $table->uuid('field_id');
            $table->uuid('target_record_id');
            $table->primary(['source_record_id', 'field_id', 'target_record_id'], 'structured_relations_primary');
            $table->foreign('source_record_id')->references('id')->on('structured_records')->cascadeOnDelete();
            $table->foreign('field_id')->references('id')->on('structured_fields')->cascadeOnDelete();
            $table->foreign('target_record_id')->references('id')->on('structured_records')->cascadeOnDelete();
        });

        Schema::create('structured_record_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('database_id')->nullable();
            $table->uuid('record_id')->nullable();
            $table->unsignedInteger('actor_id')->nullable();
            $table->string('action', 32);
            $table->json('snapshot');
            $table->timestamp('created_at');
            $table->foreign('database_id')->references('id')->on('structured_databases')->cascadeOnDelete();
            $table->foreign('record_id')->references('id')->on('structured_records')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('structured_record_changes');
        Schema::dropIfExists('structured_relations');
        Schema::dropIfExists('structured_records');
        Schema::dropIfExists('structured_fields');
        Schema::dropIfExists('structured_databases');
    }
};
