<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\ConfigController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    // Publique : l'application doit pouvoir se configurer avant toute connexion
    Route::get('config/categories', [ConfigController::class, 'categories']);
});
