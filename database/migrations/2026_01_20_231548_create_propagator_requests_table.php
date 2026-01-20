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
            $table->id();
            $table->string('requestId');
            $table->string('requestMethod');
            $table->text('requestUrl');
            $table->json('requestHeaders');
            $table->json('requestQueryParams');
            $table->longText('requestBody');
            $table->string('requestIp')->nullable();
            $table->string('requestUserAgent')->nullable();
            $table->timestampTz('requestReceivedAt')->comment('UTC');
            $table->timestamps();

            $table->unique('requestId');
            $table->index('requestReceivedAt');
        });
    }

    public function down(): void
    {
        $tableName = config('propagator.table_prefix', 'propagator_') . 'requests';

        Schema::dropIfExists($tableName);
    }
};
