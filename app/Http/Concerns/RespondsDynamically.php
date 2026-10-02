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
}
