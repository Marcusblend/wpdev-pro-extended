<?php

declare(strict_types=1);

namespace ProExtended\Site;

/**
 * A record of what this plugin changed, and when.
 *
 * Across a hundred sites the question that comes up is not "what can this
 * write" but "what did it write, on this site, last Tuesday". Backups answer
 * that for the handful of things they cover; this answers it for everything,
 * cheaply: one line per write, with the tool, who ran it, what it touched and
 * whether it was a dry run.
 *
 * Kept in a single non-autoloaded option, newest last, capped. Nothing here is
 * load-bearing — a journal write that fails must never fail the write it was
 * recording — so every path swallows its own errors.
 */
final class WriteJournal
{
    public const OPTION = 'pe_write_journal';

    /** Entries kept before the oldest are dropped. */
    public const LIMIT = 250;

    /** Result keys worth recording, in the order they are looked for. */
    private const TARGET_KEYS = ['post_id', 'document_id', 'template_id', 'menu', 'attachment_id'];

    /**
     * Record one write.
     *
     * @param array<string, mixed> $arguments
     * @param mixed                $result
     */
    public function record(string $tool, array $arguments, mixed $result): void
    {
        try {
            $entry = [
                'at'      => gmdate('c'),
                'tool'    => $tool,
                'user'    => get_current_user_id(),
                'dry_run' => ! empty($arguments['dry_run']),
            ];

            $target = self::targetOf($arguments, $result);

            if ($target !== null) {
                $entry['target'] = $target;
            }

            $summary = self::summarize($result);

            if ($summary !== null) {
                $entry['summary'] = $summary;
            }

            $journal = $this->all();
            $journal[] = $entry;

            if (count($journal) > self::LIMIT) {
                $journal = array_slice($journal, -self::LIMIT);
            }

            update_option(self::OPTION, $journal, false);
        } catch (\Throwable) {
            // A journal is never worth failing a write over.
        }
    }

    /**
     * The journal, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function read(?string $tool = null, int $limit = 50, bool $includeDryRuns = true): array
    {
        $entries = array_reverse($this->all());
        $rows = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if ($tool !== null && $tool !== '' && ($entry['tool'] ?? null) !== $tool) {
                continue;
            }

            if (! $includeDryRuns && ! empty($entry['dry_run'])) {
                continue;
            }

            $rows[] = $entry;

            if (count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    /**
     * How many entries are held, and the span they cover.
     *
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        $all = $this->all();
        $tools = [];

        foreach ($all as $entry) {
            if (is_array($entry) && is_string($entry['tool'] ?? null)) {
                $tools[$entry['tool']] = ($tools[$entry['tool']] ?? 0) + 1;
            }
        }

        arsort($tools);

        return [
            'entries' => count($all),
            'limit'   => self::LIMIT,
            'oldest'  => $all === [] ? null : ($all[0]['at'] ?? null),
            'newest'  => $all === [] ? null : ($all[count($all) - 1]['at'] ?? null),
            'by_tool' => $tools,
        ];
    }

    public function clear(): void
    {
        delete_option(self::OPTION);
    }

    /**
     * @return array<int, mixed>
     */
    private function all(): array
    {
        $journal = get_option(self::OPTION, []);

        return is_array($journal) ? array_values($journal) : [];
    }

    /**
     * What a call touched, from its arguments or its result.
     *
     * @param  array<string, mixed> $arguments
     * @return array<string, mixed>|null
     */
    public static function targetOf(array $arguments, mixed $result): ?array
    {
        foreach (self::TARGET_KEYS as $key) {
            $value = is_array($result) ? ($result[$key] ?? null) : null;
            $value ??= $arguments[$key] ?? null;

            if (is_int($value) || (is_string($value) && $value !== '')) {
                return ['kind' => $key, 'id' => $value];
            }
        }

        return null;
    }

    /**
     * A few words on what changed, taken from the result's own reporting.
     */
    public static function summarize(mixed $result): ?string
    {
        if (! is_array($result)) {
            return null;
        }

        $parts = [];

        foreach (['written', 'added', 'changed', 'removed', 'applied', 'operations_applied', 'elements'] as $key) {
            $value = $result[$key] ?? null;

            if (is_array($value) && $value !== []) {
                $parts[] = sprintf('%s %d', $key, count($value));
            } elseif (is_int($value) && $value > 0) {
                $parts[] = sprintf('%s %d', $key, $value);
            }
        }

        foreach (['backup_id', 'write_path'] as $key) {
            if (is_string($result[$key] ?? null) && $result[$key] !== '') {
                $parts[] = $key . ' ' . $result[$key];
            }
        }

        return $parts === [] ? null : implode(', ', $parts);
    }
}
