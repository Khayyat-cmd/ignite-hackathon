<?php

namespace App\Http\Controllers;

use App\Models\DevicePushToken;
use App\Models\Responder;
use App\Services\Demo\DemoWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DevicePushTokenController extends Controller
{
    public function update(Request $request, string $deviceId, DemoWorkspace $workspace): JsonResponse
    {
        $request->merge(['deviceId' => $deviceId]);
        $data = $request->validate([
            'deviceId' => 'required|uuid',
            'responderId' => 'required|uuid',
            'platform' => 'required|in:android,ios',
            'token' => 'required|string|max:4096',
        ]);
        $responder = Responder::query()->where('organization_id', $workspace->operator()->organization_id)
            ->where('authorized', true)->findOrFail($data['responderId']);
        $tokenHash = hash('sha256', $data['token']);

        DevicePushToken::query()->where('token_hash', $tokenHash)
            ->where(function ($query) use ($responder, $deviceId): void {
                $query->where('responder_id', '!=', $responder->id)->orWhere('device_id', '!=', $deviceId);
            })->delete();

        $registration = DevicePushToken::query()->updateOrCreate(
            ['responder_id' => $responder->id, 'device_id' => $deviceId],
            [
                'organization_id' => $responder->organization_id,
                'platform' => $data['platform'],
                'token_hash' => $tokenHash,
                'token' => $data['token'],
            ],
        );

        return response()->json(['registered' => true], $registration->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request, string $deviceId, DemoWorkspace $workspace): JsonResponse
    {
        $request->merge(['deviceId' => $deviceId]);
        $data = $request->validate(['deviceId' => 'required|uuid', 'responderId' => 'required|uuid']);
        $responder = Responder::query()->where('organization_id', $workspace->operator()->organization_id)
            ->where('authorized', true)->findOrFail($data['responderId']);
        DevicePushToken::query()->where('organization_id', $responder->organization_id)
            ->where('responder_id', $responder->id)->where('device_id', $deviceId)->delete();

        return response()->json(status: 204);
    }
}
