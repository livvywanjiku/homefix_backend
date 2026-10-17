<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdateAccountRequest;
use App\Http\Requests\Account\UploadAvatarRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * The signed-in account's own details.
 *
 * Separate from AuthController so that controller stays about credentials: this
 * one never touches a password or a token, and every method resolves the user
 * from the request rather than from an id in the URL.
 */
class AccountController extends Controller
{
    public function update(UpdateAccountRequest $request): UserResource
    {
        $user = $request->user();
        $user->update($request->validated());

        return UserResource::make($user->refresh());
    }

    /**
     * Stores a new avatar, replacing the previous file.
     *
     * The old file is deleted only after the new one is safely stored, so a
     * failed upload cannot leave the account with no avatar and no way back.
     */
    public function uploadAvatar(UploadAvatarRequest $request): UserResource
    {
        $user = $request->user();
        $previous = $user->avatar_path;

        $path = $request->file('avatar')->store("avatars/{$user->id}", 'public');

        $user->update(['avatar_path' => $path]);

        if ($previous !== null) {
            Storage::disk('public')->delete($previous);
        }

        return UserResource::make($user->refresh());
    }

    public function destroyAvatar(Request $request): Response
    {
        $user = $request->user();
        $previous = $user->avatar_path;

        $user->update(['avatar_path' => null]);

        if ($previous !== null) {
            Storage::disk('public')->delete($previous);
        }

        return response()->noContent();
    }
}
