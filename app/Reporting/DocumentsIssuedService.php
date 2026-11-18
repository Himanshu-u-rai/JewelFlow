<?php

namespace App\Reporting;

use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

/**
 * GSTR-1 table 13 — documents issued in a period, per document type and number series. Read-only.
 *
 * Invoices come from invoice_number_events, because that table keeps every number ever taken, cancelled or
 * voided ones included. A number belongs to the period of its document's date:
 *   - normally COALESCE(finalized_at, created_at), the accounting date every sales report uses;
 *   - a VOIDED number (a draft that moved into the next financial year and took a new number there) belongs
 *     to the date the draft was created, i.e. the year it was numbered in.
 * "Cancelled" = cancelled invoices + voided numbers + drafts that never became a bill (their number was taken
 * and will never appear on a sale, which is how GST treats a skipped number). Net = total - cancelled.
 *
 * Credit notes come from credit_notes by issued_at (the date GSTR-1 files them under); a credit note cannot be
 * cancelled in the app, so its cancelled count is 0. Quick bills have their own table and numbers and are not read.
 *
 * Series = the number's text with the running number taken out ("INV/26-27/", "INV-", "SH-#/26-27").
 */
class DocumentsIssuedService
{
    public const TYPE_INVOICE = 'Invoices for outward supply';
    public const TYPE_CREDIT_NOTE = 'Credit note';

    /**
     * @return array<int, array{doc_type:string, series:string, from:string, to:string, total:int, cancelled:int, net:int}>
     */
    public function summary(int $shopId, ReportPeriod $period): array
    {
        [$start, $end] = $period->bounds();

        $docs = [];
        foreach ($this->invoiceNumbers($shopId, $start, $end) as $r) {
            $docs[] = [self::TYPE_INVOICE, (string) $r->invoice_number, (int) $r->sequence_value, $this->isCancelled($r)];
        }
        foreach ($this->creditNotes($shopId, $start, $end) as $r) {
            $docs[] = [self::TYPE_CREDIT_NOTE, (string) $r->credit_note_number, (int) $r->credit_note_sequence, false];
        }

        $groups = [];
        foreach ($docs as [$type, $number, $sequence, $cancelled]) {
            $series = $this->series($number, $sequence);
            $g = &$groups[$type . "\0" . $series];
            $g ??= ['doc_type' => $type, 'series' => $series, 'min' => $sequence, 'max' => $sequence, 'from' => $number, 'to' => $number, 'total' => 0, 'cancelled' => 0];
            if ($sequence < $g['min']) {
                $g['min'] = $sequence;
                $g['from'] = $number;
            }
            if ($sequence > $g['max']) {
                $g['max'] = $sequence;
                $g['to'] = $number;
            }
            $g['total']++;
            $g['cancelled'] += $cancelled ? 1 : 0;
            unset($g);
        }

        $rows = [];
        foreach ($groups as $g) {
            $rows[] = [
                'doc_type' => $g['doc_type'], 'series' => $g['series'], 'from' => $g['from'], 'to' => $g['to'],
                'total' => $g['total'], 'cancelled' => $g['cancelled'], 'net' => $g['total'] - $g['cancelled'],
            ];
        }
        usort($rows, static fn ($a, $b) => [$a['doc_type'] === self::TYPE_INVOICE ? 0 : 1, $a['series']] <=> [$b['doc_type'] === self::TYPE_INVOICE ? 0 : 1, $b['series']]);

        return $rows;
    }

    private function isCancelled(object $r): bool
    {
        return in_array($r->event_type, ['cancelled', 'voided'], true)
            || in_array($r->status, [Invoice::STATUS_CANCELLED, Invoice::STATUS_DRAFT], true);
    }

    private function invoiceNumbers(int $shopId, $start, $end)
    {
        $date = 'COALESCE(CASE WHEN e.event_type = \'voided\' THEN i.created_at ELSE COALESCE(i.finalized_at, i.created_at) END, e.created_at)';

        return DB::table('invoice_number_events as e')
            ->leftJoin('invoices as i', fn ($j) => $j->on('i.id', '=', 'e.invoice_id')->on('i.shop_id', '=', 'e.shop_id'))
            ->where('e.shop_id', $shopId)
            ->whereRaw("{$date} BETWEEN ? AND ?", [$start, $end])
            ->select('e.invoice_number', 'e.sequence_value', 'e.event_type', 'i.status')
            ->get();
    }

    private function creditNotes(int $shopId, $start, $end)
    {
        return DB::table('credit_notes')
            ->where('shop_id', $shopId)
            ->whereBetween('issued_at', [$start, $end])
            ->select('credit_note_number', 'credit_note_sequence')
            ->get();
    }

    /** The number's text around its running number: "INV/26-27/1001" -> "INV/26-27/", "SH-1001/26-27" -> "SH-#/26-27". */
    private function series(string $number, int $sequence): string
    {
        if (! preg_match_all('/(?<!\d)' . $sequence . '(?!\d)/', $number, $m, PREG_OFFSET_CAPTURE)) {
            return $number;
        }
        $at = end($m[0]);
        $prefix = substr($number, 0, $at[1]);
        $suffix = substr($number, $at[1] + strlen((string) $sequence));

        return $suffix === '' ? $prefix : $prefix . '#' . $suffix;
    }
}
