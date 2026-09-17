<?php

declare(strict_types=1);

namespace ProExtended\Settings;

use ProExtended\Support\Json;

/**
 * Reads Cornerstone's JSON settings options for editing.
 */
final class StoredList
{
    /**
     * Decode an option state from SettingsBackups::readRaw() as a list.
     *
     * @param  array{exists: bool, raw: string|null, value: mixed, autoload: string|null} $state
     * @return array<int, mixed>
     *
     * @throws \RuntimeException When the stored value exists but cannot be read.
     */
    public static function listFrom(array $state, string $option): array
    {
        if (! $state['exists'] || ! is_string($state['value']) || trim($state['value']) === '') {
            return [];
        }

        $decoded = Json::decodeStored($state['value']);

        if ($decoded === null || ($decoded !== [] && ! array_is_list($decoded))) {
            throw new \RuntimeException(sprintf('The stored %s could not be read as a list; nothing was written.', $option));
        }

        return $decoded;
    }

    /**
     * Decode an option state as an object.
     *
     * @param  array{exists: bool, raw: string|null, value: mixed, autoload: string|null} $state
     * @return array<string, mixed>
     *
     * @throws \RuntimeException When the stored value exists but cannot be read.
     */
    public static function objectFrom(array $state, string $option): array
    {
        if (! $state['exists'] || ! is_string($state['value']) || trim($state['value']) === '') {
            return [];
        }

        $decoded = Json::decodeStored($state['value']);

        if ($decoded === null || ($decoded !== [] && array_is_list($decoded))) {
            throw new \RuntimeException(sprintf('The stored %s could not be read as an object; nothing was written.', $option));
        }

        return $decoded;
    }

    /**
     * Encode a list or object for storage the way Cornerstone stores it.
     */
    public static function encode(array $value, bool $object = false): string
    {
        return wp_slash(Json::encodeCs($object && $value === [] ? new \stdClass() : $value));
    }
}
