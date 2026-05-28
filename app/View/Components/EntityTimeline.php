<?php

namespace App\View\Components;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\Component;

class EntityTimeline extends Component
{
    public function __construct(
        public LengthAwarePaginator $feed,
        public string $title = 'Activity',
        public string $entityLabel = '',
    ) {}

    public function render(): View|Closure|string
    {
        return view('components.entity-timeline');
    }
}
