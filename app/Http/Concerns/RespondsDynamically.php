<?php

namespace App\Http\Concerns;

trait RespondsDynamically
{
    protected function dynamicRedirect(string $route, array $params = [], string $message = '', string $type = 'success')
    {
        if (request()->expectsJson() || request()->ajax()) {
            return response()->json([
                'success' => $type === 'success',
                'message' => $message,
            ]);
        }

        return redirect()->route($route, $params)->with($type, $message);
    }

    protected function turboStreamAppend(string $targetId, string $partial, array $data, string $message, string $fallbackRoute, array $fallbackParams = [])
    {
        $accept = request()->header('Accept', '');
        if (str_contains($accept, 'text/vnd.turbo-stream.html')) {
            $html = view($partial, $data)->render();
            $escapedMessage = e($message);

            $stream = '<turbo-stream action="append" target="' . e($targetId) . '">'
                . '<template>' . $html . '</template>'
                . '</turbo-stream>'
                . '<turbo-stream action="append" target="turbo-stream-toasts">'
                . '<template><span data-toast-message="' . $escapedMessage . '"></span></template>'
                . '</turbo-stream>';

            return response($stream, 200, ['Content-Type' => 'text/vnd.turbo-stream.html']);
        }

        return redirect()->route($fallbackRoute, $fallbackParams)->with('success', $message);
    }
}
