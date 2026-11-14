<?php

namespace App\Http\Controllers;

use App\Services\ShopNoticeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The header bell, read again without reloading the page: on opening it, after a download, and on a slow timer while
 * the tab is visible, so a finished export or a fixed warning shows up where the user already is.
 */
class NoticeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return $this->bell($request);
    }

    /** The bell's "Clear" on Ready downloads: this user's messages only. */
    public function clearDownloads(Request $request): JsonResponse
    {
        ShopNoticeService::clearDownloads($request->user());

        return $this->bell($request);
    }

    private function bell(Request $request): JsonResponse
    {
        $notices = ShopNoticeService::forUser($request->user());

        return response()->json([
            'count' => $notices['count'],
            'reorder_count' => $notices['reorder_count'],
            'html' => view('components.notice-bell-body', ['notices' => $notices])->render(),
        ]);
    }
}
