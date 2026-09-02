<?php

namespace Modules\CustomerPortal\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CustomerPortal\Models\CustomerAccount;
use Modules\CustomerPortal\Services\PortalChatService;
use Modules\CustomerPortal\Services\PortalContactService;
use Modules\CustomerPortal\Services\PortalSupportInfoService;

class PortalSupportController
{
    public function info(PortalSupportInfoService $supportInfo): JsonResponse
    {
        return response()->json([
            'data' => $supportInfo->build(),
        ]);
    }

    public function contact(Request $request, PortalContactService $contactService): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'subject' => ['required', 'string', 'max:200'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        /** @var CustomerAccount|null $account */
        $account = $request->user();

        $inquiry = $contactService->submit($account, $validated);

        return response()->json([
            'data' => [
                'id' => $inquiry->id,
                'message' => 'Thanks for reaching out. Our team will get back to you soon.',
            ],
        ], 201);
    }

    public function chat(Request $request, PortalChatService $chat): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
        ]);

        /** @var CustomerAccount|null $account */
        $account = $request->user();

        return response()->json([
            'data' => $chat->respond($validated['message'], $account),
        ]);
    }
}
