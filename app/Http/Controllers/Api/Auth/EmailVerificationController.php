<?php

namespace App\Http\Controllers\Api\Auth;

use App\Application\Authentication\EmailVerificationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function __construct(
        private readonly EmailVerificationService $verification,
    ) {}

    public function send(Request $request): JsonResponse
    {
        $sent = $this->verification->sendNotification($request->user());

        return response()->json([
            'message' => $sent ? __('api.verification_link_sent') : __('api.email_already_verified'),
        ]);
    }

    /**
     * Opened from the emailed link (signed URL). Browsers are redirected to the frontend; API clients get JSON.
     */
    public function verify(Request $request, int $id, string $hash): JsonResponse|RedirectResponse
    {
        $this->verification->verify($id, $hash);

        if ($request->expectsJson()) {
            return response()->json(['message' => __('api.email_verified')]);
        }

        return redirect()->away(config('game.frontend.email_verified_url').'?status=verified');
    }
}
