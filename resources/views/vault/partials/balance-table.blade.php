<div class="vault-scroll vault-balance-scroll" data-list-scroll tabindex="0" role="region" aria-label="{{ $label }}">
    <table id="{{ $tableId }}" class="jf-table vault-table" role="table" aria-label="{{ $label }}">
        <thead role="rowgroup"><tr role="row">
            <th scope="col" role="columnheader" class="jf-table-index" aria-label="Row number">#</th>
            <th scope="col" role="columnheader">Material</th><th scope="col" role="columnheader">Purity</th>
            <th scope="col" role="columnheader" class="jf-num">In Vault (g)</th><th scope="col" role="columnheader" class="jf-num">With Karigar (g)</th>
            <th scope="col" role="columnheader" class="jf-num">Total fine (g)</th><th scope="col" role="columnheader" class="jf-num">Lots</th>
        </tr></thead>
        <tbody role="rowgroup">
            @foreach($rows as $row)
                <tr role="row">
                    <td role="cell" class="jf-table-index">{{ $loop->iteration }}</td>
                    <td role="cell" data-label="Material" class="capitalize"><strong>{{ $row['metal_type'] ?? 'Unknown material' }}</strong></td>
                    <td role="cell" data-label="Purity">{{ rtrim(rtrim(number_format($row['purity'], 2), '0'), '.') }}{{ ($row['metal_type'] ?? null) === 'gold' ? 'K' : '' }}</td>
                    <td role="cell" data-label="In Vault (g)" class="jf-num">{{ number_format($row['in_vault_fine'], 3) }}</td>
                    <td role="cell" data-label="With Karigar (g)" class="jf-num">{{ number_format($row['with_karigar_fine'], 3) }}</td>
                    <td role="cell" data-label="Total fine (g)" class="jf-num"><strong>{{ number_format($row['total_fine'], 3) }}</strong></td>
                    <td role="cell" data-label="Lots" class="jf-num">{{ $row['lots_count'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
