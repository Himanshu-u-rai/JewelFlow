<?php

namespace Tests\Feature;

use App\Support\Csv;
use Tests\TestCase;

/**
 * PHP 8.4 deprecates fputcsv()/fgetcsv() without an explicit $escape, and PHP 9
 * flips the default from "\\" to "". Every bare call in app/ was therefore both
 * emitting a deprecation on every export AND pre-committed to a silent
 * behaviour change on the next major.
 *
 * App\Support\Csv makes that one decision once. These tests pin the decision and
 * stop the bare calls from growing back.
 */
class CsvEscapeContractTest extends TestCase
{
    /** @return resource */
    private function buffer()
    {
        return fopen('php://temp', 'r+');
    }

    public function test_a_value_ending_in_a_backslash_survives_a_write_read_round_trip(): void
    {
        // The exact case the legacy "\\" escape mangles: the trailing backslash
        // escapes the closing quote, so the field runs into the next one and a
        // shop's export opens misaligned in Excel.
        $row = ['C:\\exports\\', 'next column', 'he said "hi"'];

        $handle = $this->buffer();
        Csv::put($handle, $row);
        rewind($handle);

        $this->assertSame($row, Csv::get($handle));
    }

    public function test_the_written_form_is_rfc_4180_doubled_quotes(): void
    {
        $handle = $this->buffer();
        Csv::put($handle, ['he said "hi"']);
        rewind($handle);

        // Doubled quote, not a backslash escape — what Excel and Sheets expect.
        $this->assertSame("\"he said \"\"hi\"\"\"\n", stream_get_contents($handle));
    }

    /**
     * The values a jewellery shop actually manages to put in a text field, run
     * through the REAL streamed exporter rather than the helper in isolation.
     *
     * CsvReportExporter had no test of its own, so the four call sites converted
     * in it were covered only by the helper's unit round-trip. This drives the
     * whole path — BOM, streamed callback, header row, body rows — and parses
     * the bytes back, which is the only assertion that would notice the export
     * opening column-shifted in Excel.
     *
     * @return list<string>
     */
    private function adversarialRow(): array
    {
        return [
            'C:\\exports\\',          // trailing backslash — the corruption case
            'he said "hi"',           // embedded quotes
            'Ring, 22K',              // embedded delimiter
            "two\nlines",             // embedded newline
            '=SUM(A1:A9)',            // formula-looking, must survive verbatim
            '-500',                   // signed number
            '₹ आभूषण',                // non-ASCII (BOM/UTF-8 path)
            '',                       // empty field
            '  padded  ',             // significant whitespace
            'back\\slash"and,quote',  // all three at once
        ];
    }

    public function test_the_real_streamed_exporter_round_trips_adversarial_values(): void
    {
        $headers = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'];
        $row     = $this->adversarialRow();

        $response = \App\Reporting\Export\CsvReportExporter::fromRows('hammer.csv', $headers, [$row]);

        ob_start();
        $response->sendContent();
        $csv = ob_get_clean();

        // Strip the UTF-8 BOM the exporter deliberately writes for Excel.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'BOM should still be emitted');
        $csv = substr($csv, 3);

        $handle = $this->buffer();
        fwrite($handle, $csv);
        rewind($handle);

        $this->assertSame($headers, Csv::get($handle), 'header row did not survive');
        $this->assertSame($row, Csv::get($handle), 'data row did not survive the round trip');
        $this->assertFalse(Csv::get($handle), 'exporter emitted more rows than it was given');
    }

    /**
     * A field ending in a backslash is the case that silently shifts columns.
     * Proven here against the legacy escape so the regression is documented as
     * a real data bug, not just a deprecation notice.
     */
    public function test_the_legacy_escape_really_did_corrupt_the_backslash_case(): void
    {
        $row = ['C:\\exports\\', 'next column'];

        $legacy = $this->buffer();
        fputcsv($legacy, $row, ',', '"', "\\");   // what every call site used to do
        rewind($legacy);
        $legacyParsed = fgetcsv($legacy, 0, ',', '"', "\\");

        $fixed = $this->buffer();
        Csv::put($fixed, $row);
        rewind($fixed);

        $this->assertNotSame($row, $legacyParsed,
            'if the legacy escape round-tripped cleanly this whole change was unnecessary');
        $this->assertSame($row, Csv::get($fixed),
            'the shared helper must round-trip what the legacy escape mangled');
    }

    /**
     * The quadrant a migration forgets: old writer, new reader.
     *
     * legacy->legacy and new->new are the two coherent worlds, and both are
     * covered above. Deploy day is neither — it is every CSV this app already
     * wrote, read back by the new escape. That mixed window lasts exactly as
     * long as those files do.
     *
     * Two shapes matter, and they fail in opposite directions:
     *
     *   'C:\exports\'  legacy wrote it corrupt; the new reader recovers it.
     *   '10\"'         legacy wrote it fine; the new reader mis-parses it.
     *
     * The second is real jewellery data — `18\"` is an 18-inch chain. This test
     * does not fix that; nothing can, short of guessing which escape wrote a
     * file. It pins the boundary so the next person meets a failing assertion
     * instead of a support ticket about a shifted column.
     */
    public function test_a_file_written_by_the_legacy_escape_is_only_partly_readable_by_the_new_one(): void
    {
        // Written corrupt by the legacy escape — the new reader recovers it,
        // because the bytes on disk were never quoted the way legacy assumed.
        $this->assertSame(
            ['C:\\exports\\', 'next column'],
            $this->readBack(['C:\\exports\\', 'next column'], "\\"),
            'the new reader should recover the case the legacy writer mangled'
        );

        // Written cleanly by the legacy escape — and the new reader cannot get
        // it back: `\"` on the wire is "escaped quote" to legacy and "backslash,
        // then end of field" under RFC 4180. Known, accepted, bounded.
        $this->assertNotSame(
            ['10\\"', 'next column'],
            $this->readBack(['10\\"', 'next column'], "\\"),
            'if this ever round-trips, the mixed-escape window closed and this test can go'
        );

        // The two only collide when a backslash sits directly before a quote.
        // Everything else in a jeweller's export crosses the boundary intact.
        foreach ([['25mm \\ 3g', 'x'], ['he said "hi"', 'x'], ['plain', 'x']] as $row) {
            $this->assertSame($row, $this->readBack($row, "\\"),
                'ordinary values must survive the escape change: '.$row[0]);
        }
    }

    /**
     * Write a row with an arbitrary escape, read it back with the app's one.
     *
     * @param  array<int, string>  $row
     * @return array<int, string|null>|false
     */
    private function readBack(array $row, string $writeEscape): array|false
    {
        $handle = $this->buffer();
        fputcsv($handle, $row, ',', '"', $writeEscape);
        rewind($handle);

        return Csv::get($handle);
    }

    public function test_no_csv_call_in_the_app_bypasses_the_shared_escape(): void
    {
        $helper = realpath(app_path('Support/Csv.php'));
        $offenders = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || $file->getRealPath() === $helper) {
                continue;
            }

            $source = file_get_contents($file->getRealPath());

            foreach (['fputcsv', 'fgetcsv', 'str_getcsv'] as $fn) {
                if (preg_match('/(?<![\w:>$])' . $fn . '\s*\(/', $source) === 1) {
                    $offenders[] = str_replace(base_path() . '/', '', $file->getRealPath()) . " calls {$fn}()";
                }
            }
        }

        $this->assertSame([], $offenders,
            "Use App\\Support\\Csv instead — a bare call takes PHP's default \$escape, "
            . "which is deprecated in 8.4 and changes meaning in 9.\n" . implode("\n", $offenders));
    }
}
