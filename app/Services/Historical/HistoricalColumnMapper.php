<?php

namespace App\Services\Historical;

use App\Models\Historical\HistoricalImportProfile;
use App\Support\Historical\HistoricalFields;
use App\Support\Historical\HistoricalMessages;

/**
 * Applies a CONFIRMED column mapping to a source row, and refuses to let a
 * mapping be confirmed while it still hides money.
 *
 * The alias table in HistoricalFields prefills this screen. It never completes
 * it. The rule that matters is the negative one: a source column that was
 * neither mapped nor explicitly dismissed is a blocking error, because "we did
 * not notice this column" and "the operator decided this column is irrelevant"
 * look identical in the data afterwards — and one of them silently drops a
 * discount, a cess or a second making charge out of every total in the archive.
 */
class HistoricalColumnMapper
{
    public const CODE_REQUIRED_UNMAPPED   = 'mapping_required_unmapped';
    public const CODE_UNKNOWN_FIELD       = 'mapping_unknown_field';
    public const CODE_COLUMN_UNDECIDED    = 'mapping_column_undecided';
    public const CODE_COLUMN_MISSING      = 'mapping_column_missing';
    public const CODE_ALIAS_SUGGESTED     = 'mapping_alias_suggested';
    public const CODE_COLUMN_IGNORED      = 'mapping_column_ignored';
    public const CODE_JOIN_KEY_MISSING    = 'mapping_join_key_missing';
    public const CODE_LINE_FIELDS_UNUSED  = 'mapping_line_fields_unused';

    /**
     * Header-level canonical values for one source row.
     *
     * @param  array<string, mixed>  $cells  source header => raw value
     * @param  array<string, string>  $mapping  canonical field => source header
     * @return array<string, mixed>
     */
    public function header(array $cells, array $mapping): array
    {
        return $this->pick($cells, $mapping, array_keys(HistoricalFields::HEADER));
    }

    /**
     * @param  array<string, mixed>  $cells
     * @param  array<string, string>  $mapping
     * @return array<string, mixed>
     */
    public function line(array $cells, array $mapping): array
    {
        return $this->pick($cells, $mapping, array_keys(HistoricalFields::LINE));
    }

    /** True when the row carries anything at all at line level. */
    public function hasLineData(array $cells, array $mapping): bool
    {
        foreach ($this->line($cells, $mapping) as $value) {
            if ($value !== null && trim((string) (is_scalar($value) ? $value : '.')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * The value of the confirmed join key for Layout C, or null.
     */
    public function joinKey(array $cells, array $mapping, string $field = HistoricalFields::JOIN_KEY): ?string
    {
        $column = $mapping[$field] ?? null;

        if (! is_string($column) || $column === '') {
            return null;
        }

        $value = $cells[$column] ?? null;

        if ($value === null || ! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Everything wrong with a mapping, before a single row is normalized.
     *
     * @param  array<string, string>  $mapping  canonical field => source header
     * @param  array<int, string>  $headers  the source file's actual headers
     * @param  array<string, string>  $decisions  source header => ignored|informational
     */
    public function validate(
        array $mapping,
        array $headers,
        array $decisions,
        string $layout,
    ): HistoricalMessages {
        $messages = new HistoricalMessages();

        foreach (HistoricalFields::REQUIRED as $field) {
            if (! isset($mapping[$field]) || $mapping[$field] === '') {
                $messages->error(
                    self::CODE_REQUIRED_UNMAPPED,
                    sprintf('"%s" must be mapped to a column before this file can be imported.', self::label($field)),
                    $field
                );
            }
        }

        foreach ($mapping as $field => $column) {
            if (! HistoricalFields::isKnown((string) $field)) {
                $messages->error(
                    self::CODE_UNKNOWN_FIELD,
                    sprintf('"%s" is not a field this module can import into.', $field),
                    (string) $field
                );

                continue;
            }

            if ($column === '' || $column === null) {
                continue;
            }

            if (! in_array((string) $column, $headers, true)) {
                $messages->error(
                    self::CODE_COLUMN_MISSING,
                    sprintf('"%s" is mapped to a column "%s" that this file does not have.', self::label((string) $field), $column),
                    (string) $field
                );
            }
        }

        if ($layout === HistoricalImportProfile::LAYOUT_HEADER_DETAIL) {
            foreach ([HistoricalFields::JOIN_KEY, HistoricalFields::DETAIL_JOIN_KEY] as $field) {
                if (! isset($mapping[$field]) || $mapping[$field] === '') {
                    $messages->error(
                        self::CODE_JOIN_KEY_MISSING,
                        'A header/detail import needs the column that links a detail row to its invoice, on both sheets. '
                        . 'Row order is not a link.',
                        $field
                    );
                }
            }
        }

        if ($layout === HistoricalImportProfile::LAYOUT_HEADER_ONLY) {
            $lineFields = array_intersect(array_keys($mapping), array_keys(HistoricalFields::LINE));

            if ($lineFields !== []) {
                $messages->warning(
                    self::CODE_LINE_FIELDS_UNUSED,
                    'This layout imports one bill per row, so the mapped item-line columns will not be read. '
                    . 'Switch to "one row per line item" if this file has item detail.',
                    'layout_type'
                );
            }
        }

        $this->reportUndecidedColumns($mapping, $headers, $decisions, $messages);

        return $messages;
    }

    /**
     * Every source column has to end up in exactly one of three states: mapped,
     * ignored, or informational. Nothing may simply be absent.
     */
    private function reportUndecidedColumns(
        array $mapping,
        array $headers,
        array $decisions,
        HistoricalMessages $messages,
    ): void {
        $mapped = array_map('strval', array_filter(array_values($mapping), static fn ($c) => is_string($c) && $c !== ''));

        foreach ($headers as $header) {
            $header = (string) $header;

            if ($header === '' || in_array($header, $mapped, true)) {
                continue;
            }

            $decision = $decisions[$header] ?? null;

            if ($decision === HistoricalImportProfile::DECISION_IGNORED
                || $decision === HistoricalImportProfile::DECISION_INFORMATIONAL) {
                $messages->info(
                    self::CODE_COLUMN_IGNORED,
                    sprintf('Column "%s" is %s by explicit decision.', $header, $decision),
                    $header
                );

                continue;
            }

            $messages->error(
                self::CODE_COLUMN_UNDECIDED,
                sprintf(
                    'Column "%s" is neither mapped nor marked as ignored. Decide what it is — a column that is '
                    . 'silently skipped is money that silently disappears.',
                    $header
                ),
                $header
            );
        }
    }

    /**
     * Alias prefill for the mapping screen, plus the informational trail that
     * says which suggestions were made. Suggestions are NOT applied to a profile
     * by this method; the caller shows them and the operator confirms.
     *
     * @param  array<int, string>  $headers
     * @return array{mapping: array<string, string>, unmapped: array<int, string>, messages: HistoricalMessages}
     */
    public function suggest(array $headers): array
    {
        $suggestion = HistoricalFields::suggestAll($headers);
        $messages   = new HistoricalMessages();

        foreach ($suggestion['mapping'] as $field => $column) {
            $messages->info(
                self::CODE_ALIAS_SUGGESTED,
                sprintf('Column "%s" looks like "%s". Confirm or change it.', $column, self::label($field)),
                $field
            );
        }

        return $suggestion + ['messages' => $messages];
    }

    /**
     * @param  array<string, mixed>  $cells
     * @param  array<string, string>  $mapping
     * @param  array<int, string>  $fields
     * @return array<string, mixed>
     */
    private function pick(array $cells, array $mapping, array $fields): array
    {
        $picked = [];

        foreach ($fields as $field) {
            $column = $mapping[$field] ?? null;

            $picked[$field] = is_string($column) && $column !== ''
                ? ($cells[$column] ?? null)
                : null;
        }

        return $picked;
    }

    private static function label(string $field): string
    {
        return ucfirst(str_replace('_', ' ', $field));
    }
}
