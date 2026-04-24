<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class TranslationController extends Controller
{
    public function show(string $locale): JsonResponse
    {
        $normalized = str_replace('-', '_', strtolower(trim($locale)));
        $supported = array_keys(config('app.supported_locales', ['en' => 'English']));

        if (!in_array($normalized, $supported, true)) {
            return response()->json([], 404);
        }

        if ($normalized === 'en') {
            return response()->json([]);
        }

        $path = lang_path($normalized.'.json');
        if (!is_file($path)) {
            return response()->json([]);
        }

        $contents = file_get_contents($path);
        $decoded = json_decode($contents, true);

        if (!is_array($decoded)) {
            return response()->json([], 500);
        }

        return response()
            ->json($decoded)
            ->header('Cache-Control', 'public, max-age=300');
    }
}
