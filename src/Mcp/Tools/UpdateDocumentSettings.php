<?php

declare(strict_types=1);

namespace ProExtended\Mcp\Tools;

use ProExtended\Cornerstone\DocumentGateway;
use ProExtended\Cornerstone\DocumentSettings;
use ProExtended\Layouts\LayoutService;
use ProExtended\Mcp\ToolPermissionException;
use ProExtended\Support\Args;
use ProExtended\Support\Json;
use ProExtended\Support\JsonArgs;

final class UpdateDocumentSettings implements ToolInterface, AnnotatedToolInterface
{
    private const ARGUMENTS = ['document_id', 'settings', 'title', 'slug', 'dry_run'];

    private readonly DocumentGateway $gateway;

    public function __construct(
        private readonly LayoutService $layouts,
    ) {
        $this->gateway = $layouts->gateway();
    }

    public function name(): string
    {
        return 'update_document_settings';
    }

    public function description(): string
    {
        return 'Change some settings (and optionally the title, or a component\'s slug) of a Cornerstone header, footer, layout or component document without touching its elements. Settings not given keep their values. Backs up first; restore_layout with the returned backup_id restores the settings, title and slug. Returns before/after for each changed key.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['document_id'],
            'properties' => [
                'document_id' => [
                    'type'        => 'integer',
                    'description' => 'ID of the header, footer, layout or component document.',
                ],
                'settings' => [
                    'type'        => 'object',
                    'description' => 'Settings to change (same keys as create_document). Pass "assignments": [] to unassign.',
                ],
                'title' => [
                    'type'        => 'string',
                    'description' => 'Optional. New title.',
                ],
                'slug' => [
                    'type'        => 'string',
                    'description' => 'Optional. New slug (components only).',
                ],
                'dry_run' => [
                    'type'        => 'boolean',
                    'description' => 'Optional. Report the changes and write nothing. Default: false.',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $arguments = JsonArgs::decode($arguments, ['settings']);
        Args::rejectUnknown($arguments, self::ARGUMENTS, 'arguments');

        $id = Args::postId($arguments, 'document_id');
        $post = get_post($id);

        if (! $post instanceof \WP_Post) {
            throw new \InvalidArgumentException(sprintf('Post %d does not exist.', $id));
        }

        $docType = $this->gateway->docTypeForPost($post);

        $isContent = $docType !== null && str_starts_with($docType, 'content:');

        if ($docType === null || (! $isContent && $this->layouts->detectSource($post) !== 'post_content')) {
            throw new \InvalidArgumentException(sprintf('Post %d is not a Cornerstone document or page.', $id));
        }

        if ($this->gateway->isLegacyGlobalBlock($post)) {
            throw new \InvalidArgumentException(sprintf('Document %d is a legacy global block. Open it in Cornerstone once to convert it.', $id));
        }

        $isComponent = $this->gateway->isComponentDocType($docType);
        $settings = DocumentSettings::validate($docType, Args::object($arguments, 'settings') ?? []);
        $title = Args::string($arguments, 'title', null, 200);
        $slug = Args::string($arguments, 'slug', null, 200);
        $dryRun = Args::bool($arguments, 'dry_run', false);

        if ($title !== null) {
            $title = sanitize_text_field($title);

            if ($title === '') {
                throw new \InvalidArgumentException('"title" must not be empty.');
            }
        }

        if ($slug !== null) {
            if (! $isComponent) {
                throw new \InvalidArgumentException('"slug" can only be changed on component documents.');
            }

            $slug = sanitize_title($slug);

            if ($slug === '') {
                throw new \InvalidArgumentException('"slug" must not be empty.');
            }
        }

        // A layout override points at another document; check it is there and
        // is the right kind, or the page silently falls back to the default.
        foreach (DocumentSettings::LAYOUT_OVERRIDES as $key => $expected) {
            if (! isset($settings[$key])) {
                continue;
            }

            $override = DocumentSettings::readOverride($settings[$key]);

            if ($override['mode'] !== 'document') {
                continue;
            }

            $target = get_post($override['id']);

            if (! $target instanceof \WP_Post) {
                throw new \InvalidArgumentException(sprintf('"%s" points at post %d, which does not exist.', $key, $override['id']));
            }

            $targetType = $this->gateway->docTypeForPost($target);

            if ($targetType !== $expected) {
                throw new \InvalidArgumentException(sprintf(
                    '"%s" must point at a %s document; post %d is %s.',
                    $key,
                    $expected,
                    $override['id'],
                    $targetType === null ? 'not a Cornerstone document' : $targetType
                ));
            }
        }

        if ($settings === [] && $title === null && $slug === null) {
            throw new \InvalidArgumentException('Nothing to change: pass settings, title or slug.');
        }

        if (DocumentSettings::hasCode($settings) && ! current_user_can('unfiltered_html')) {
            throw new ToolPermissionException('Setting customCSS or customJS requires the unfiltered_html capability.');
        }

        if ($isContent) {
            // A page keeps its settings in meta beside its elements.
            $current = $this->gateway->readPageSettings($id);
        } else {
            $stored = Json::decodeStored($post->post_content) ?? [];
            $current = is_array($stored['settings'] ?? null) ? $stored['settings'] : [];
        }

        $defaults = $this->gateway->storedDefaults($docType);
        $changes = [];

        foreach ($settings as $key => $value) {
            $before = array_key_exists($key, $current) ? $current[$key] : ($defaults[$key] ?? null);

            if ($before !== $value) {
                $changes[$key] = ['before' => $before, 'after' => $value];
            }
        }

        if ($title !== null && $title !== $post->post_title) {
            $changes['title'] = ['before' => $post->post_title, 'after' => $title];
        }

        if ($slug !== null && $slug !== $post->post_name) {
            $changes['slug'] = ['before' => $post->post_name, 'after' => $slug];
        }

        $result = [
            'updated'     => false,
            'dry_run'     => $dryRun,
            'document_id' => $id,
            'doc_type'    => $docType,
            'changes'     => (object) $changes,
            'backup_id'   => null,
            'write_path'  => null,
            'warnings'    => [],
        ];

        if ($changes === [] || $dryRun) {
            if ($changes === []) {
                $result['warnings'][] = 'The document already has these values; nothing was written.';
            }

            return $result;
        }

        $backupId = $this->layouts->backup($id, [
            'post_title'  => $post->post_title,
            'post_name'   => $post->post_name,
            'source_tool' => 'update_document_settings',
        ]);

        if ($isContent) {
            if ($title !== null) {
                wp_update_post(['ID' => $id, 'post_title' => $title]);
            }

            $written = $settings === [] ? null : $this->gateway->updatePageSettings($id, $settings);

            $result['updated'] = true;
            $result['write_path'] = $written['path'] ?? 'post-meta';
            $result['backup_id'] = $backupId;

            return $result;
        }

        $update = [];

        if ($settings !== []) {
            $update['settings'] = $settings;
        }

        if ($title !== null) {
            $update['title'] = $title;
        }

        if ($slug !== null) {
            $update['slug'] = $slug;
        }

        $write = $this->gateway->updateDocument($id, $update);

        // Report what was actually stored (WordPress may make a slug unique).
        if (isset($changes['slug'])) {
            $changes['slug']['after'] = (string) get_post_field('post_name', $id);
        }

        $result['updated'] = true;
        $result['changes'] = (object) $changes;
        $result['backup_id'] = $backupId;
        $result['write_path'] = $write['path'];
        $result['warnings'] = $write['warnings'];

        return $result;
    }

    public function annotations(): array
    {
        return Annotations::write('Update Document Settings', true, true);
    }

    public function requiredCapability(): string
    {
        return 'manage_options';
    }
}
