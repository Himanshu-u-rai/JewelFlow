{{-- Row number for Dhiran tables (owner 2026-11-11): continues across pages when $rows is a paginator. --}}
@props(['rows' => null, 'n'])
<td data-label="#" class="dh-sno" data-sno>{{ $n + ($rows instanceof \Illuminate\Pagination\AbstractPaginator ? $rows->firstItem() - 1 : 0) }}</td>
