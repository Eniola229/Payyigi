<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Korapay\KorapayVirtualAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VirtualAccountController extends Controller
{
    public function __construct(
        private readonly KorapayVirtualAccountService $korapay
    ) {}

    /**
     * POST /api/v1/wallet/virtual-account/generate
     */
    public function generate(Request $request): JsonResponse
    {
        $user = $request->user();

        // Already has one
        if ($user->virtual_account_active) {
            return response()->json([
                'message' => 'Virtual account already exists.',
                'data'    => [
                    'account_number' => $user->virtual_account_number,
                    'bank_name'      => $user->virtual_account_bank,
                    'account_name'   => $user->virtual_account_name,
                ],
            ]);
        }

        // Must have either NIN or BVN verified
        if (!$user->nin_verified && !$user->bvn_verified) {
            return response()->json([
                'message' => 'Please verify your NIN or BVN before generating a virtual account.',
                'action'  => 'verify_identity',
            ], 403);
        }

        try {
            // Use whichever identity is verified
            // NIN takes priority if both are verified
            if ($user->nin_verified) {
                $identityNumber = $user->nin; // decrypted via cast
                $identityType   = 'nin';
            } else {
                $identityNumber = $user->bvn; // decrypted via cast
                $identityType   = 'bvn';
            }

            $reference   = 'VBA-' . $user->id;

            $accountData = $this->korapay->createVirtualAccount(
                name:      $user->full_name,
                email:     $user->email,
                reference: $reference,
                bvnOrNin:  $identityNumber,
            );

            $user->update([
                'virtual_account_number'     => $accountData['account_number'],
                'virtual_account_bank'       => $accountData['bank_name']        ?? $accountData['bank'],
                'virtual_account_bank_code'  => $accountData['bank_code']        ?? null,
                'virtual_account_name'       => $accountData['account_name'],
                'virtual_account_reference'  => $accountData['account_reference'] ?? $reference,
                'virtual_account_active'     => true,
                'virtual_account_created_at' => now(),
            ]);

            AuditLog::record('user.virtual_account_generated', [
                'user_id'    => $user->id,
                'new_values' => [
                    'account_number' => $accountData['account_number'],
                    'bank'           => $accountData['bank_name'] ?? null,
                    'identity_used'  => $identityType, // log which was used, not the number
                    'reference'      => $reference,
                ],
            ]);

            return response()->json([
                'message' => 'Virtual account created successfully. Send NGN to this account to top up your wallet.',
                'data'    => [
                    'account_number' => $user->virtual_account_number,
                    'bank_name'      => $user->virtual_account_bank,
                    'account_name'   => $user->virtual_account_name,
                    'note'           => 'This is your permanent PayYigi wallet account. Any amount sent here will be credited to your wallet after fees.',
                ],
            ], 201);

        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /api/v1/wallet/virtual-account
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->virtual_account_active) {
            return response()->json([
                'message' => 'No virtual account found. Please generate one.',
                'action'  => 'generate_virtual_account',
            ], 404);
        }

        return response()->json([
            'data' => [
                'account_number' => $user->virtual_account_number,
                'bank_name'      => $user->virtual_account_bank,
                'account_name'   => $user->virtual_account_name,
                'active'         => $user->virtual_account_active,
                'created_at'     => $user->virtual_account_created_at,
                'note'           => 'Send NGN to this account to top up your PayYigi wallet.',
                'fee_info'       => config('payyigi.topup_fee_percent', 0.5) . '% processing fee applies.',
            ],
        ]);
    }
}