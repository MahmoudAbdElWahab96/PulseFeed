<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FeedController;
use App\Http\Controllers\Api\FollowController;
use App\Http\Controllers\Api\LikeController;
use App\Http\Controllers\Api\PostController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
    Route::get('/user', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/feed', [FeedController::class, 'index']);
    Route::get('/posts/{post}', [PostController::class, 'show'])->whereNumber('post');

    Route::middleware('throttle:writes')->group(function (): void {
        Route::post('/follow/{user_id}', [FollowController::class, 'store'])->whereNumber('user_id');
        Route::delete('/follow/{user_id}', [FollowController::class, 'destroy'])->whereNumber('user_id');

        Route::post('/posts', [PostController::class, 'store']);
        Route::delete('/posts/{post}', [PostController::class, 'destroy'])->whereNumber('post');

        Route::post('/posts/{post}/like', [LikeController::class, 'store'])->whereNumber('post');
        Route::delete('/posts/{post}/like', [LikeController::class, 'destroy'])->whereNumber('post');
    });
});
