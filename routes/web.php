<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ReconciliationController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\AuthController;

// Welcome page (no auth required)
Route::get('/', function () {
    return view('welcome');
});

// Authentication routes (no auth required)
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);

// Logout route (no auth required - users can logout)
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Protected routes - require authentication
Route::middleware('auth.check')->group(function () {
    Route::get('/dashboard', [ReconciliationController::class, 'dashboard'])->name('dashboard');

    Route::prefix('reconciliations')->group(function () {
        Route::get('/', [ReconciliationController::class, 'index'])->name('reconciliations.index');
        Route::get('/manage', function () {
            return view('reconciliations.manage');
        })->name('reconciliations.manage');
        Route::get('/matches', [ReconciliationController::class, 'matches'])->name('reconciliations.matches');
        Route::post('/matches/find', [ReconciliationController::class, 'runMatches'])->name('reconciliations.matches.find');
        Route::delete('/matches', [ReconciliationController::class, 'deleteMatches'])->name('reconciliations.matches.delete');
        Route::get('/create', [ReconciliationController::class, 'create'])->name('reconciliations.create');
        Route::post('/', [ReconciliationController::class, 'store'])->name('reconciliations.store');
        Route::get('/{id}', [ReconciliationController::class, 'show'])->name('reconciliations.show');
        Route::get('/{id}/export', [ReconciliationController::class, 'export'])->name('reconciliations.export');
    });

    Route::prefix('imports')->group(function () {
        Route::get('/', [ImportController::class, 'index'])->name('imports.index');
        Route::get('/history', [ImportController::class, 'history'])->name('imports.history');
        Route::post('/upload', [ImportController::class, 'upload'])->name('imports.upload');
        Route::post('/viefund', [ImportController::class, 'vieFundUpload'])->name('imports.viefund.upload');
        Route::post('/fundserv', [ImportController::class, 'fundservUpload'])->name('imports.fundserv.upload');
        
        // Unified transaction management routes
        Route::get('/transactions/{type?}', [ImportController::class, 'transactions'])->name('imports.transactions');
        
        // Legacy transaction routes (kept for backwards compatibility with truncate)
        Route::get('/viefund/transactions', [ImportController::class, 'vieFundTransactions'])->name('imports.viefund.transactions');
        Route::get('/fundserv/transactions', [ImportController::class, 'fundservTransactions'])->name('imports.fundserv.transactions');
        Route::get('/bank/transactions', [ImportController::class, 'bankTransactions'])->name('imports.bank.transactions');
        Route::delete('/viefund/transactions/{id}', [ImportController::class, 'deleteVieFund'])->name('imports.viefund.delete');
        Route::delete('/fundserv/transactions/{id}', [ImportController::class, 'deleteFundserv'])->name('imports.fundserv.delete');
        Route::delete('/viefund/truncate', [ImportController::class, 'truncateVieFund'])->name('imports.viefund.truncate');
        Route::delete('/fundserv/truncate', [ImportController::class, 'truncateFundserv'])->name('imports.fundserv.truncate');
        Route::delete('/bank/truncate', [ImportController::class, 'truncateBank'])->name('imports.bank.truncate');
        
        // Import record management
        Route::delete('/history/{id}', [ImportController::class, 'deleteImport'])->name('imports.delete');
        Route::get('/history/{id}/view', [ImportController::class, 'viewImport'])->name('imports.view');
    });
});
