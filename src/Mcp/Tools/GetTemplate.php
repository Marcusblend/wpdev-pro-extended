<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Support\Args;
use ProExtended\Templates\TemplateGateway;

final class GetTemplate implements ToolInterface, AnnotatedToolInterface
{
    public function __construct(private readonly TemplateGateway $templates) {}

    public function name(): string
    {
        return 'get_template';
    }

    public function description(): string
    {
        return 'Read one template from the library, with the content Cornerstone stores for it: elements for a block or document, atts for a preset (the element settings the preset applies). Migrations run on the way out, so the content comes back in the current shape.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['template_id'],
            'properties' => [
                'template_id' => [
                    'type'        => 'integer',
                    'description' => 'The template\'s post ID, from list_templates.',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        Args::rejectUnknown($arguments, ['template_id'], 'arguments');

        $id = Args::int($arguments, 'template_id', null, 1) ?? 0;

        if ($id <= 0) {
            throw new \InvalidArgumentException('template_id must be a positive integer.');
        }

        $template = $this->templates->get($id);

        if ($template === null) {
            throw new \InvalidArgumentException(sprintf('No template with ID %d. Use list_templates to see them.', $id));
        }

        return $template;
    }

    public function annotations(): array
    {
        return Annotations::read('Get Template');
    }

    public function requiredCapability(): string
    {
        return 'edit_posts';
    }
}
