<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Elements\SchemaExtractor;
use ProExtended\Support\Args;
use ProExtended\Templates\TemplateGateway;
use ProExtended\Templates\TemplateIdentifier;

final class ImportTco implements ToolInterface, AnnotatedToolInterface
{
    private const MAX_BYTES = 8 * 1024 * 1024;

    public function __construct(
        private readonly TemplateGateway $templates,
        private readonly SchemaExtractor $schema,
    ) {}

    public function name(): string
    {
        return 'import_tco';
    }

    public function description(): string
    {
        return 'Add the templates in a .tco archive to this site\'s library. Pass the archive as base64 in tco. Without confirm: true nothing is written and the response lists what the archive holds, which is the way to run it first. Each entry\'s type and subtype are checked against this site\'s element and document types, and an entry this site cannot take is reported and skipped rather than failing the rest. Attachments referenced by the templates are not fetched: images arrive as the URLs the archive carries.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['tco'],
            'properties' => [
                'tco' => [
                    'type'        => 'string',
                    'description' => 'The .tco archive, base64 encoded (what export_tco returns).',
                ],
                'confirm' => [
                    'type'        => 'boolean',
                    'description' => 'Required to write. Without it the archive is only described. Default: false.',
                ],
                'titles' => [
                    'type'        => 'object',
                    'description' => 'Optional. Rename entries on the way in: the title in the archive => the title to save it under.',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        Args::rejectUnknown($arguments, ['tco', 'confirm', 'titles'], 'arguments');

        if (! $this->templates->available()) {
            throw new \RuntimeException('This site has no Cornerstone template library.');
        }

        $encoded = (string) Args::string($arguments, 'tco', null, 0, false);
        $confirm = Args::bool($arguments, 'confirm', false);
        $titles = Args::object($arguments, 'titles') ?? [];

        $raw = base64_decode($encoded, true);

        if ($raw === false || $raw === '') {
            throw new \InvalidArgumentException('tco is not base64. Pass the string export_tco returns.');
        }

        if (strlen($raw) > self::MAX_BYTES) {
            throw new \InvalidArgumentException(sprintf('The archive is %d bytes, over the %d byte limit.', strlen($raw), self::MAX_BYTES));
        }

        $path = wp_tempnam('pe-import.tco');

        if (! is_string($path) || $path === '' || file_put_contents($path, $raw) === false) {
            throw new \RuntimeException('The archive could not be written to a temporary file.');
        }

        try {
            $archive = $this->templates->readArchive($path);
        } finally {
            @unlink($path);
        }

        $elementTypes = $this->elementTypes();
        $documentTypes = $this->templates->documentTypes();

        $planned = [];
        $skipped = [];

        foreach ($archive['entries'] as $entry) {
            $data = $entry['data'];

            if (! is_array($data) || ! isset($data['type'], $data['subType'])) {
                continue; // The manifest and anything else that is not a template.
            }

            $type = (string) $data['type'];
            $subType = (string) $data['subType'];
            $title = is_string($data['title'] ?? null) && $data['title'] !== '' ? $data['title'] : 'Untitled';
            $problems = TemplateIdentifier::problems($type, $subType, $elementTypes, $documentTypes);

            if ($problems !== []) {
                $skipped[] = ['title' => $title, 'identifier' => TemplateIdentifier::format($type, $subType), 'reason' => $problems[0]];
                continue;
            }

            $planned[] = [
                'title'      => is_string($titles[$title] ?? null) ? (string) $titles[$title] : $title,
                'from_title' => $title,
                'type'       => $type,
                'sub_type'   => $subType,
                'identifier' => TemplateIdentifier::format($type, $subType),
                'kind'       => TemplateIdentifier::kind($type, $subType),
                'meta'       => is_array($data['meta'] ?? null) ? $data['meta'] : [],
            ];
        }

        if (! $confirm) {
            return [
                'confirmed' => false,
                'files'     => $archive['files'],
                'would_add' => array_map(static fn (array $row): array => [
                    'title'      => $row['title'],
                    'identifier' => $row['identifier'],
                    'kind'       => $row['kind'],
                ], $planned),
                'skipped'   => $skipped,
                'note'      => 'Nothing was written. Call again with confirm: true to add these.',
            ];
        }

        $added = [];
        $failed = [];

        foreach ($planned as $row) {
            try {
                $created = $this->templates->create($row['type'], $row['sub_type'], $row['title'], $row['meta']);
                $added[] = ['id' => $created['id'], 'title' => $row['title'], 'identifier' => $row['identifier'], 'kind' => $row['kind']];
            } catch (\Throwable $e) {
                $failed[] = ['title' => $row['title'], 'identifier' => $row['identifier'], 'reason' => $e->getMessage()];
            }
        }

        $result = [
            'confirmed' => true,
            'added'     => $added,
            'skipped'   => $skipped,
        ];

        if ($failed !== []) {
            $result['failed'] = $failed;
        }

        return $result;
    }

    /**
     * @return string[]
     */
    private function elementTypes(): array
    {
        $types = [];

        foreach ($this->schema->getElementList() as $element) {
            if (is_string($element['type'] ?? null) && $element['type'] !== '') {
                $types[] = $element['type'];
            }
        }

        return $types;
    }

    public function annotations(): array
    {
        return Annotations::write('Import .tco', false, true);
    }

    public function requiredCapability(): string
    {
        return 'edit_posts';
    }
}
