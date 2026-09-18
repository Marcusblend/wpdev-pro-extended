<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Elements\SchemaExtractor;
use ProExtended\Layouts\LayoutService;
use ProExtended\Support\Args;
use ProExtended\Support\JsonArgs;
use ProExtended\Templates\TemplateGateway;
use ProExtended\Templates\TemplateIdentifier;

final class CreateTemplate implements ToolInterface, AnnotatedToolInterface
{
    public function __construct(
        private readonly TemplateGateway $templates,
        private readonly SchemaExtractor $schema,
        private readonly LayoutService $layouts,
    ) {}

    public function name(): string
    {
        return 'create_template';
    }

    public function description(): string
    {
        return 'Save something into the Cornerstone template library. identifier is "<type>|<subtype>": "element|__multi__" for a block of elements, "element|<element type>" for a preset of that element\'s settings, or "document|layout:header" and the like for a whole document. Pass content (elements for a block or document, atts for a preset) or from_document to capture an existing document\'s layout instead. The identifier is checked against this site\'s element and document types first, because Cornerstone fatals on one it does not know. Run with dry_run: true first.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['identifier', 'title'],
            'properties' => [
                'identifier' => [
                    'type'        => 'string',
                    'description' => 'The template kind, as "<type>|<subtype>". list_templates reports the site\'s document types.',
                ],
                'title' => [
                    'type'        => 'string',
                    'description' => 'The name it appears under in the library.',
                ],
                'content' => [
                    'type'        => ['object', 'string'],
                    'description' => 'Optional. The template data: {"elements": [...]} for a block or document, {"atts": {...}} for a preset. Accepts a JSON string.',
                ],
                'from_document' => [
                    'type'        => 'integer',
                    'description' => 'Optional. Capture this post or document\'s layout as the template content instead of passing content.',
                ],
                'preview' => [
                    'type'        => 'string',
                    'description' => 'Optional. Preview image URL or attachment reference.',
                ],
                'dry_run' => [
                    'type'        => 'boolean',
                    'description' => 'Optional. Report what would be saved and write nothing. Default: false.',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        Args::rejectUnknown($arguments, ['identifier', 'title', 'content', 'from_document', 'preview', 'dry_run'], 'arguments');

        if (! $this->templates->available()) {
            throw new \RuntimeException('This site has no Cornerstone template library.');
        }

        $arguments = JsonArgs::decode($arguments, ['content']);

        $identifier = (string) Args::string($arguments, 'identifier', null, 100, false);
        $title = (string) Args::string($arguments, 'title', null, 200, false);
        $preview = (string) (Args::string($arguments, 'preview', '', 2000) ?? '');
        $dryRun = Args::bool($arguments, 'dry_run', false);
        $fromDocument = Args::int($arguments, 'from_document', null, 1);
        $content = Args::object($arguments, 'content');

        $parts = TemplateIdentifier::parse($identifier);
        $type = $parts['type'];
        $subType = $parts['sub_type'];

        $problems = TemplateIdentifier::problems($type, $subType, $this->elementTypes(), $this->templates->documentTypes());

        if ($problems !== []) {
            throw new \InvalidArgumentException("The identifier cannot be used:\n- " . implode("\n- ", $problems));
        }

        if ($content !== null && $fromDocument !== null) {
            throw new \InvalidArgumentException('Pass content or from_document, not both.');
        }

        if ($fromDocument !== null) {
            $content = $this->captureDocument($fromDocument, $type, $subType);
        }

        if ($content === null || $content === []) {
            throw new \InvalidArgumentException('Pass content, or from_document to capture an existing layout.');
        }

        $content = $this->checkContent($content, $type, $subType);

        if ($dryRun) {
            return [
                'dry_run'    => true,
                'identifier' => $identifier,
                'kind'       => TemplateIdentifier::kind($type, $subType),
                'title'      => $title,
                'would_write' => array_keys($content),
            ];
        }

        $result = $this->templates->create($type, $subType, $title, $content, $preview);
        $result['content_keys'] = array_keys($content);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function captureDocument(int $postId, string $type, string $subType): array
    {
        $envelope = $this->layouts->get($postId);
        $data = $envelope['data'] ?? null;

        if (! is_array($data)) {
            throw new \InvalidArgumentException(sprintf('Post %d has no Cornerstone layout to capture.', $postId));
        }

        // A document template keeps its regions and settings; a block keeps a
        // flat list of elements, which is what a page layout already is.
        if ($type === TemplateIdentifier::DOCUMENT) {
            return isset($data['regions']) ? $data : ['elements' => $data];
        }

        if (isset($data['regions']) && is_array($data['regions'])) {
            $elements = [];

            foreach ($data['regions'] as $region) {
                foreach ((array) $region as $element) {
                    $elements[] = $element;
                }
            }

            return ['elements' => $elements];
        }

        return ['elements' => $data];
    }

    /**
     * @param  array<string, mixed> $content
     * @return array<string, mixed>
     */
    private function checkContent(array $content, string $type, string $subType): array
    {
        if (TemplateIdentifier::isPreset($type, $subType)) {
            if (! isset($content['atts']) || ! is_array($content['atts'])) {
                throw new \InvalidArgumentException('A preset\'s content is {"atts": {...}}: the element settings it applies. Use get_element_schema to see the keys, or get_template on an existing preset.');
            }

            // Cornerstone runs a preset's atts through the element migrations,
            // which read _type to know which migrations apply. Without it every
            // setting is dropped and the preset saves as "unknown", so the
            // element type the preset is for is filled in here.
            $declared = $content['atts']['_type'] ?? null;

            if (is_string($declared) && $declared !== '' && $declared !== $subType) {
                throw new \InvalidArgumentException(sprintf('This preset is for "%s", so atts._type must be "%s" or left out, not "%s".', $subType, $subType, $declared));
            }

            $content['atts']['_type'] = $subType;

            if (count($content['atts']) < 2) {
                throw new \InvalidArgumentException('A preset with no settings would apply nothing. Pass the element keys it should set in atts.');
            }

            return $content;
        }

        if (! isset($content['elements']) && ! isset($content['regions'])) {
            throw new \InvalidArgumentException('A block or document template\'s content is {"elements": [...]} (or a document\'s regions).');
        }

        return $content;
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
        return Annotations::write('Create Template', false, true);
    }

    public function requiredCapability(): string
    {
        return 'edit_posts';
    }
}
