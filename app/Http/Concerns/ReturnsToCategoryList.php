<?php

namespace App\Http\Concerns;

use Illuminate\Support\Arr;

trait ReturnsToCategoryList
{
    /**
     * The search and the page of the Categories list a change was made from, so
     * its answer goes back to the same view of the list instead of page 1 of
     * everything. Read from where the request came from, and only when that is
     * the Categories list; nothing but these two values is taken from it.
     *
     * @return array<string, string>
     */
    protected function categoryListQuery(): array
    {
        $from = url()->previous();

        if (parse_url($from, PHP_URL_PATH) !== parse_url(route('categories.index'), PHP_URL_PATH)) {
            return [];
        }

        parse_str((string) parse_url($from, PHP_URL_QUERY), $query);

        return array_filter(Arr::only($query, ['q', 'page']), fn ($value) => is_string($value) && $value !== '');
    }
}
