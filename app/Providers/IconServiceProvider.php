<?php

namespace App\Providers;

use App\View\Icons;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

/**
 * Compiles plain <x-icon name="..." /> tags into a direct call to App\View\Icons::svg() when a view is
 * compiled (once, then cached), so an icon costs a function call at request time instead of a Blade component.
 */
class IconServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Blade::prepareStringsForCompilationUsing(
            fn (string $template): string => str_contains($template, '<x-icon') ? Icons::compileTags($template) : $template
        );
    }
}
