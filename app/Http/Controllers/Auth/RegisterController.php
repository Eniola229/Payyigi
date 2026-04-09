<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Wallet;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RegisterController extends Controller
{
    public function __invoke(RegisterRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request) {
            $user = User::create([
                'first_name'    => $request->first_name,
                'last_name'     => $request->last_name,
                'email'         => strtolower($request->email),
                'phone'         => $request->phone,
                'password'      => $request->password, // mutator hashes it
                'referral_code' => strtoupper(Str::random(8)),
                'referred_by'   => $this->resolveReferrer($request->referral_code),
            ]);

            // Create NGN wallet automatically
            Wallet::create([
                'user_id'  => $user->id,
                'currency' => 'NGN',
                'balance'  => 0.00,
            ]);

            return $user;
        });

        // Send email verification
        $user->notify(new VerifyEmailNotification());

        AuditLog::record('user.registered', [
            'user_id'    => $user->id,
            'new_values' => ['email' => $user->email],
        ]);

        return response()->json([
            'message' => 'Registration successful. Please check your email to verify your account.',
            'data'    => [
                'id'    => $user->id,
                'email' => $user->email,
                'name'  => $user->full_name,
            ],
        ], 201);
    }

    private function resolveReferrer(?string $code): ?string
    {
        if (!$code) return null;

        return User::where('referral_code', $code)->value('id');
    }
}
