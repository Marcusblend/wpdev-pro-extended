<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Support\Args;
use ProExtended\Templates\TemplateGateway;
use ProExtended\Templates\TemplateIdentifier;

final class ListTemplates implements ToolInterface, AnnotatedToolInterface
{
    public function __construct(private readonly TemplateGateway $templates) {}

    public function name(): string
    {
        return 'list_templates';
    }

    public function description(): string
    {
        return 'List the site\'s Cornerstone template library. Each row reports its id, title, identifier ("<type>|<subtype>") and kind: a block (element|__multi__, elements insertable anywhere), a preset (element|<element type>, saved settings for one kind of element) or a document (document|layout:header and the like). Narrow with kind, identifier or search. Use get_template to read one, export_tco to take some away.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'kind' => [
                    'type'        => 'string',
                    'enum'        => ['block', 'preset', 'document'],
                    'description' => 'Optional. Only templates of this kind.',
                ],
                'identifier' => [
                    'type'        => 'string',
                    'description' => 'Optional. An exact identifier, such as "element|headline" or "document|layout:header".',
                ],
                'search' => [
                    'type'        => 'string',
                    'description' => 'Optional. Match against the title.',
                ],
                'limit' => [
                    'type'        => 'integer',
                    'description' => 'Optional. Maximum rows. Default 200.',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        Args::rejectUnknown($arguments, ['kind', 'identifier', 'search', 'limit'], 'arguments');

        if (! $this->templates->available()) {
            throw new \RuntimeException('This site has no Cornerstone template library.');
        }

        $kind = Args::string($arguments, 'kind', null, 20);
        $identifier = Args::string($arguments, 'identifier', null, 100);
        $search = Args::string($arguments, 'search', null, 200);
        $limit = Args::int($arguments, 'limit', 200, 1, 500) ?? 200;

        if ($kind !== null && ! in_array($kind, ['block', 'preset', 'document'], true)) {
            throw new \InvalidArgumentException('kind must be "block", "preset" or "document".');
        }

        $rows = $this->templates->all($identifier, $search, $limit);

        if ($kind !== null) {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['kind'] === $kind));
        }

        $counts = ['block' => 0, 'preset' => 0, 'document' => 0];

        foreach ($rows as $row) {
            $counts[$row['kind']] = ($counts[$row['kind']] ?? 0) + 1;
        }

        return [
            'count'          => count($rows),
            'counts_by_kind' => $counts,
            'document_types' => $this->templates->documentTypes(),
            'multi_sub_type' => TemplateIdentifier::MULTI,
            'templates'      => $rows,
        ];
    }

    public function annotations(): array
    {
        return Annotations::read('List Templates');
    }

    public function requiredCapability(): string
    {
        return 'edit_posts';
    }
}
