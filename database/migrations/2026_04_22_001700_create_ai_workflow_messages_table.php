<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_workflow_messages', function (Blueprint $table): void {
            $table->string('reference')->primary();
            $table->string('workflow_id')->index();
            $table->string('run_id')->index();
            $table->string('role', 32);
            $table->longText('content');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_workflow_messages');
    }
};
