<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('run_id', 64);
            $table->string('signal_id');
            $table->string('source');
            $table->string('type');
            $table->string('severity', 16);
            $table->string('occurred_at', 40);
            $table->json('entity_references');
            $table->json('attributes');
            $table->string('raw_payload_id');
            $table->string('raw_payload_location')->nullable();

            // A signal id is unique within a run, enabling save() upserts and
            // keeping each run's signals isolated from other runs.
            $table->unique(['run_id', 'signal_id']);
            $table->index(['run_id', 'source']);
            $table->index(['run_id', 'type']);
            $table->index(['run_id', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        $table = config('provado.storage.table', 'provado_signals');

        return is_string($table) && trim($table) !== '' ? $table : 'provado_signals';
    }
};
