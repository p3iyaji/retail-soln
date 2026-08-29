<?php

namespace App\Support\Observability;

/**
 * In-process Prometheus text exposition. Counters reset on each PHP worker lifecycle.
 */
final class PrometheusRegistry
{
    /** @var array<string, float> */
    private static array $counters = [];

    public static function reset(): void
    {
        self::$counters = [];
    }

    /**
     * @param  array<string, string>  $labels
     */
    public static function increment(string $name, array $labels = [], float $by = 1): void
    {
        $key = self::metricKey($name, $labels);
        self::$counters[$key] = (self::$counters[$key] ?? 0) + $by;
    }

    public static function render(): string
    {
        $lines = [];
        $types = [];

        foreach (array_keys(self::$counters) as $key) {
            $types[self::metricNameFromKey($key)] = true;
        }

        foreach (array_keys($types) as $name) {
            $lines[] = '# HELP '.$name.' Counter';
            $lines[] = '# TYPE '.$name.' counter';
        }

        foreach (self::$counters as $key => $value) {
            $lines[] = $key.' '.self::formatFloat($value);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string, string>  $labels
     */
    private static function metricKey(string $name, array $labels): string
    {
        if ($labels === []) {
            return $name;
        }

        ksort($labels);
        $parts = [];
        foreach ($labels as $label => $value) {
            $parts[] = $label.'="'.$value.'"';
        }

        return $name.'{'.implode(',', $parts).'}';
    }

    private static function metricNameFromKey(string $key): string
    {
        return str_contains($key, '{') ? strstr($key, '{', true) : $key;
    }

    private static function formatFloat(float $value): string
    {
        if (floor($value) === $value) {
            return (string) (int) $value;
        }

        return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
    }
}
