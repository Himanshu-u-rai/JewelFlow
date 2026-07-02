{{-- Staged opening rows for one kind, with delete. $rows, $batch, $cols(field=>label). --}}
@if ($rows->isNotEmpty())
    <table style="width:100%;border-collapse:collapse;font-size:13px;margin-top:8px;">
        @foreach ($rows as $e)
            <tr style="border-top:1px solid #f1f5f9;">
                @foreach ($cols as $field => $label)
                    @if (array_key_exists($field, $e->payload))
                        <td style="padding:4px 8px;color:#475569;">{{ $label }}: {{ $e->payload[$field] }}</td>
                    @endif
                @endforeach
                <td style="padding:4px 8px;text-align:right;">
                    <form method="POST" action="{{ route('onboarding.entries.destroy', [$batch, $e]) }}">
                        @csrf @method('DELETE')
                        <button type="submit" style="background:none;border:none;color:#991b1b;cursor:pointer;font-size:13px;">Remove</button>
                    </form>
                </td>
            </tr>
        @endforeach
    </table>
@endif
