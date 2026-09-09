<?php

namespace App\Support\Historical;

use App\Models\Historical\HistoricalImportRow;

/**
 * Everything the pipeline has to say about one row, one document or one batch.
 *
 * Three severities, and the difference between them is a policy, not a tone:
 *
 *   error   — publication is IMPOSSIBLE until a human changes something.
 *   warning — publication is possible, but only after an explicit acknowledgement
 *             that is recorded with an actor and a timestamp.
 *   info    — the operator should know what we did. Never gates anything.
 *
 * Messages are addressed to a jeweller. "SQLSTATE 23505" is not a message; "Bill
 * INV/0045 is already imported in this financial year" is.
 */
final class HistoricalMessages
{
    public const ERROR   = HistoricalImportRow::SEVERITY_ERROR;
    public const WARNING = HistoricalImportRow::SEVERITY_WARNING;
    public const INFO    = HistoricalImportRow::SEVERITY_INFO;
    public const OK      = HistoricalImportRow::SEVERITY_OK;

    /** @var array<int, array{severity: string, code: string, text: string, field: ?string}> */
    private array $messages = [];

    public function error(string $code, string $text, ?string $field = null): self
    {
        return $this->add(self::ERROR, $code, $text, $field);
    }

    public function warning(string $code, string $text, ?string $field = null): self
    {
        return $this->add(self::WARNING, $code, $text, $field);
    }

    public function info(string $code, string $text, ?string $field = null): self
    {
        return $this->add(self::INFO, $code, $text, $field);
    }

    public function add(string $severity, string $code, string $text, ?string $field = null): self
    {
        $this->messages[] = compact('severity', 'code', 'text', 'field');

        return $this;
    }

    public function merge(self $other): self
    {
        foreach ($other->messages as $message) {
            $this->messages[] = $message;
        }

        return $this;
    }

    public function hasBlocking(): bool
    {
        return $this->countOf(self::ERROR) > 0;
    }

    public function countOf(string $severity): int
    {
        return count(array_filter($this->messages, static fn (array $m): bool => $m['severity'] === $severity));
    }

    public function has(string $code): bool
    {
        foreach ($this->messages as $message) {
            if ($message['code'] === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * The single severity that describes the whole set — the worst one present.
     * Anything else would let one error hide behind ten informational notes.
     */
    public function severity(): string
    {
        return match (true) {
            $this->countOf(self::ERROR) > 0   => self::ERROR,
            $this->countOf(self::WARNING) > 0 => self::WARNING,
            $this->countOf(self::INFO) > 0    => self::INFO,
            default                           => self::OK,
        };
    }

    public function validationStatus(): string
    {
        return $this->hasBlocking()
            ? HistoricalImportRow::VALIDATION_INVALID
            : HistoricalImportRow::VALIDATION_VALID;
    }

    /** @return array<int, array{severity: string, code: string, text: string, field: ?string}> */
    public function all(): array
    {
        return $this->messages;
    }

    /** @return array<int, array{severity: string, code: string, text: string, field: ?string}> */
    public function ofSeverity(string $severity): array
    {
        return array_values(array_filter(
            $this->messages,
            static fn (array $m): bool => $m['severity'] === $severity
        ));
    }

    public function isEmpty(): bool
    {
        return $this->messages === [];
    }

    /**
     * A stable fingerprint of exactly the warnings in this set, or null when there
     * are none to acknowledge.
     *
     * This is what makes "I have read the warnings" honest across an edit. The
     * operator acknowledges a digest; publication recomputes the warnings from the
     * re-submitted raw input and recomputes the digest. If the bill changed enough
     * to change its warnings, the digests differ and the acknowledgement is refused
     * rather than silently carried over from a screen that showed something else.
     *
     * Only code+text+field go in: severity is fixed by the filter, and ordering is
     * normalised away so a reordered-but-identical warning set stays acknowledged.
     */
    public function warningDigest(): ?string
    {
        $warnings = array_map(
            static fn (array $m): string => $m['code'] . '|' . $m['text'] . '|' . ($m['field'] ?? ''),
            $this->ofSeverity(self::WARNING)
        );

        if ($warnings === []) {
            return null;
        }

        sort($warnings);

        return hash('sha256', implode("\n", $warnings));
    }

    /** @param  array<int, array<string, mixed>>|null  $messages */
    public static function fromArray(?array $messages): self
    {
        $bag = new self();

        foreach ($messages ?? [] as $message) {
            $bag->add(
                (string) ($message['severity'] ?? self::INFO),
                (string) ($message['code'] ?? 'unknown'),
                (string) ($message['text'] ?? ''),
                isset($message['field']) ? (string) $message['field'] : null,
            );
        }

        return $bag;
    }
}
