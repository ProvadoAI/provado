<?php

use Illuminate\Support\Facades\Route;

Route::prefix('provado')->group(function (): void {
    Route::get('/health', function (): array {
        return [
            'ok' => true,
            'package' => 'provado',
        ];
    });
});
