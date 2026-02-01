<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('propagator.table_prefix', 'propagator_') . 'requests';

        Schema::create($tableName, function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('method');
            $table->text('path');
            $table->json('headers');
            $table->json('query_params');
            $table->longText('body');
            $table->string('ip')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestampTz('received_at')->comment('UTC');
            $table->timestamps();

            $table->index('received_at');
        });
    }

    public function down(): void
    {
        $tableName = config('propagator.table_prefix', 'propagator_') . 'requests';

        Schema::dropIfExists($tableName);
    }
};
