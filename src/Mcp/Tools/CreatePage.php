<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Elements\HierarchyValidator;
use ProExtended\Layouts\LayoutService;
use ProExtended\Support\Args;
use ProExtended\Support\JsonArgs;

final class CreatePage implements ToolInterface, AnnotatedToolInterface
{
    public function __construct(
        private readonly LayoutService $layouts,
        private readonly ?HierarchyValidator $validator = null,
    ) {}

    public function name(): string
    {
        return 'create_page';
    }

    public function description(): string
    {
        return 'Create a new WordPress page, optionally with Cornerstone layout data (validated before the page is created). Supports a parent page, menu order, page template and excerpt; with if_not_exists: true an existing page with the same slug (or, for a draft without a slug, the same title) and parent is returned instead of creating another. Returns the new post ID.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['title'],
            'properties' => [
                'title' => [
                    'type'        => 'string',
                    'description' => 'Page title.',
                ],
                'slug' => [
                    'type'        => 'string',
                    'description' => 'Optional. Page slug (post_name). Auto-generated from title if not provided.',
                ],
                'status' => [
                    'type'        => 'string',
                    'enum'        => ['draft', 'publish', 'private'],
                    'description' => 'Optional. Post status. Default: "draft".',
                ],
                'layout_data' => [
                    'type'        => ['array', 'null'],
                    'description' => 'Optional. Cornerstone layout data (element tree array).',
                ],
                'parent_id' => [
                    'type'        => 'integer',
                    'description' => 'Optional. ID of an existing parent page.',
                ],
                'menu_order' => [
                    'type'        => 'integer',
                    'description' => 'Optional. Page order.',
                ],
                'template' => [
                    'type'        => 'string',
                    'description' => 'Optional. Page template file (for example "template-blank-4.php") or "default". When omitted, template-blank-4.php is set if layout data is saved.',
                ],
                'excerpt' => [
                    'type'        => 'string',
                    'description' => 'Optional. Manual excerpt.',
                ],
                'if_not_exists' => [
                    'type'        => 'boolean',
                    'description' => 'Optional. When true and a page with the same slug and parent exists (a draft without a slug matches on its title), return it and write nothing. Default: false.',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $arguments = JsonArgs::decode($arguments, ['layout_data']);

        $title  = $arguments['title'] ?? '';
        $slug   = $arguments['slug'] ?? '';
        $status = $arguments['status'] ?? 'draft';
        $layout = $arguments['layout_data'] ?? null;

        if (! is_string($title) || trim($title) === '') {
            throw new \InvalidArgumentException('title is required.');
        }

        if (! is_string($slug)) {
            throw new \InvalidArgumentException('slug must be a string.');
        }

        // Validate status.
        if (! in_array($status, ['draft', 'publish', 'private'], true)) {
            throw new \InvalidArgumentException('Invalid status. Must be "draft", "publish", or "private".');
        }

        $parentId    = Args::int($arguments, 'parent_id', 0, 0) ?? 0;
        $menuOrder   = Args::int($arguments, 'menu_order', null);
        $template    = Args::string($arguments, 'template', null, 200);
        $excerpt     = Args::string($arguments, 'excerpt', null);
        $ifNotExists = Args::bool($arguments, 'if_not_exists', false);

        if ($parentId > 0) {
            $parent = get_post($parentId);

            if (! $parent || $parent->post_type !== 'page') {
                throw new \InvalidArgumentException(sprintf('parent_id %d is not an existing page.', $parentId));
            }
        }

        if ($template !== null) {
            $templates = array_keys(wp_get_theme()->get_page_templates(null, 'page'));

            if ($template !== 'default' && ! in_array($template, $templates, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Unknown page template "%s". Available: default, %s.',
                    $template,
                    implode(', ', $templates)
                ));
            }
        }

        // Validate before inserting, so an invalid layout never leaves an
        // empty page behind.
        $warnings = [];

        if ($layout !== null) {
            if (! is_array($layout)) {
                throw new \InvalidArgumentException('layout_data must be an array of elements.');
            }

            if ($this->validator !== null) {
                $validation = $this->validator->validate($layout, 'inline');

                if (! $validation->valid) {
                    throw new \InvalidArgumentException(
                        'layout_data failed validation; no page was created. ' . implode(' ', $validation->errors)
                    );
                }

                $warnings = $validation->warnings;
            }
        }

        if ($ifNotExists) {
            $existing = $this->findExisting($slug, $title, $parentId);

            if ($existing !== null) {
                return $this->result($existing, false, $warnings);
            }
        }

        $postArgs = [
            'post_title'  => sanitize_text_field($title),
            'post_type'   => 'page',
            'post_status' => $status,
        ];

        if (! empty($slug)) {
            $postArgs['post_name'] = sanitize_title($slug);
        }

        if ($parentId > 0) {
            $postArgs['post_parent'] = $parentId;
        }

        if ($menuOrder !== null) {
            $postArgs['menu_order'] = $menuOrder;
        }

        if ($excerpt !== null) {
            $postArgs['post_excerpt'] = $excerpt;
        }

        $postId = wp_insert_post(wp_slash($postArgs), true);

        if (is_wp_error($postId)) {
            throw new \RuntimeException('Failed to create page: ' . $postId->get_error_message());
        }

        // Save Cornerstone data if provided.
        if ($layout !== null) {
            try {
                $this->layouts->save($postId, $layout);
            } catch (\Throwable $e) {
                // Do not leave an empty page behind for a layout that failed to save.
                wp_delete_post($postId, true);
                throw $e;
            }
        }

        if ($template !== null) {
            update_post_meta($postId, '_wp_page_template', $template);
        }

        return $this->result($postId, true, $warnings);
    }

    /**
     * @param  string[] $warnings
     * @return array<string, mixed>
     */
    private function result(int $postId, bool $created, array $warnings): array
    {
        return [
            'post_id'  => $postId,
            'title'    => get_the_title($postId),
            'slug'     => get_post_field('post_name', $postId),
            'status'   => get_post_status($postId),
            'url'      => get_permalink($postId),
            'created'  => $created,
            'parent'   => (int) get_post_field('post_parent', $postId),
            'template' => get_page_template_slug($postId) ?: 'default',
            'warnings' => $warnings,
        ];
    }

    /**
     * A page with the same slug and parent. Without a slug, the slug WordPress
     * would derive from the title is used, and a draft (which keeps an empty
     * slug until it is published) matches on its exact title.
     */
    private function findExisting(string $slug, string $title, int $parentId): ?int
    {
        $query = [
            'post_type'        => 'page',
            'post_parent'      => $parentId,
            'post_status'      => ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page'   => 1,
            'fields'           => 'ids',
            'suppress_filters' => true,
            'orderby'          => 'ID',
            'order'            => 'ASC',
        ];

        $name = sanitize_title($slug !== '' ? $slug : $title);

        if ($name !== '') {
            $ids = get_posts($query + ['name' => $name]);

            if ($ids) {
                return (int) $ids[0];
            }
        }

        if ($slug !== '') {
            return null;
        }

        $candidates = get_posts(array_merge($query, [
            'title'          => sanitize_text_field($title),
            'post_status'    => ['draft', 'pending'],
            'posts_per_page' => 20,
        ]));

        foreach ($candidates as $id) {
            if ((string) get_post_field('post_name', (int) $id) === '') {
                return (int) $id;
            }
        }

        return null;
    }

    public function annotations(): array
    {
        return Annotations::write('Create Page', false, false);
    }

    public function requiredCapability(): string
    {
        return 'publish_pages';
    }
}
