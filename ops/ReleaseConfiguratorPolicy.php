<?php

/** Candidate-owned, read-only activation gate; no database or provider calls. */
final class ReleaseConfiguratorPolicy
{
    public static function accepts(array $configuration, array $database): bool
    {
        $preview = $configuration['preview_enabled'] ?? false;
        $purchase = $configuration['purchase_enabled'] ?? false;
        if ($preview === false && $purchase === false) {
            return true;
        }
        if ($preview !== true || ! is_bool($purchase) || ($configuration['production_approved'] ?? false) !== true) {
            return false;
        }
        $catalog = $configuration['catalog'] ?? null;
        $expected = [
            'environment' => 'production', 'driver' => 'mysql',
            'host' => (string) ($database['host'] ?? ''), 'port' => (string) ($database['port'] ?? ''),
            'database' => (string) ($database['database'] ?? ''),
        ];
        if (! is_array($catalog) || $expected['host'] === '' || $expected['port'] === ''
            || $expected['database'] === '' || in_array($expected['database'], ['upgrade', 'upgrade_test'], true)) {
            return false;
        }
        foreach ($expected as $key => $value) {
            if (($catalog[$key] ?? null) !== $value) {
                return false;
            }
        }
        $products = $configuration['products'] ?? null;
        if (! is_array($products) || $products === []) {
            return false;
        }
        foreach ($products as $id => $entry) {
            $profile = is_array($entry) ? ($entry['profile_id'] ?? null) : null;
            $definition = is_string($profile) ? ($configuration['profiles'][$profile] ?? null) : null;
            if (! ctype_digit((string) $id) || (int) $id < 1 || ! is_array($entry)
                || ($entry['reviewed'] ?? false) !== true
                || ! in_array($profile, ['vehicle-oem-memory-v1', 'vehicle-oem-reference-v1'], true)
                || ($entry['profile_version'] ?? null) !== 1
                || ! is_array($definition) || ($definition['enabled'] ?? false) !== true
                || ($definition['version'] ?? null) !== 1) {
                return false;
            }
            if ($purchase) {
                $ids = $entry['approved_variant_ids'] ?? null;
                if (! is_array($ids) || ! array_is_list($ids) || $ids === []
                    || count(array_unique($ids, SORT_REGULAR)) !== count($ids)) {
                    return false;
                }
                foreach ($ids as $variantId) {
                    if (! is_int($variantId) || $variantId < 1) {
                        return false;
                    }
                }
            }
        }

        return true;
    }
}
