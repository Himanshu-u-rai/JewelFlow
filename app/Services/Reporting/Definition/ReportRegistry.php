<?php

namespace App\Services\Reporting\Definition;

use App\Services\Reporting\Dataset\ReportDatasetService;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use RuntimeException;

/**
 * Central registry of report definitions → dataset services (frozen §3.1).
 * Reports self-register (typically in a service provider) by binding a key to
 * its `ReportDatasetService` class; the registry reads each report's
 * `definition()` for the export panel, permission checks, and the contract
 * audit — without building any data.
 *
 * Definitions are resolved lazily and cached, so listing the registry is cheap.
 */
class ReportRegistry
{
    /** @var array<string, class-string<ReportDatasetService>> */
    private array $services = [];

    /** @var array<string, ReportDefinition> */
    private array $definitions = [];

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @param class-string<ReportDatasetService> $datasetService
     */
    public function register(string $key, string $datasetService): void
    {
        if (isset($this->services[$key])) {
            throw new InvalidArgumentException("Report [{$key}] is already registered.");
        }
        $this->services[$key] = $datasetService;
    }

    public function has(string $key): bool
    {
        return isset($this->services[$key]);
    }

    public function definition(string $key): ReportDefinition
    {
        if (! isset($this->services[$key])) {
            throw new InvalidArgumentException("Unknown report [{$key}].");
        }

        return $this->definitions[$key] ??= $this->resolveDefinition($key);
    }

    public function datasetService(string $key): ReportDatasetService
    {
        if (! isset($this->services[$key])) {
            throw new InvalidArgumentException("Unknown report [{$key}].");
        }

        return $this->container->make($this->services[$key]);
    }

    /** @return string[] */
    public function keys(): array
    {
        return array_keys($this->services);
    }

    /** @return ReportDefinition[] keyed by report key. */
    public function all(): array
    {
        $out = [];
        foreach (array_keys($this->services) as $key) {
            $out[$key] = $this->definition($key);
        }
        return $out;
    }

    private function resolveDefinition(string $key): ReportDefinition
    {
        $definition = $this->datasetService($key)->definition();

        if ($definition->key !== $key) {
            throw new RuntimeException(
                "Report [{$key}] resolves a definition with mismatched key [{$definition->key}]."
            );
        }

        return $definition;
    }
}
