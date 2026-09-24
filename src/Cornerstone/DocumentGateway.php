<?php

declare(strict_types=1);

namespace ProExtended\Cornerstone;

use ProExtended\Support\Json;

/**
 * The single place Pro Extended calls into Cornerstone internals.
 *
 * Writes go through Cornerstone's own Document API when it is available, so
 * they fire the same hooks the builder fires (documents: `cs_save_document`,
 * `cs_save_{type}` and, for components, `cs_purge_tmp`; pages:
 * `cs_save_document` and the before/after content hooks) and every cache
 * Cornerstone keeps stays correct. When the API is missing or fails, a direct
 * write that produces the same stored shape is used instead, followed by the
 * same cache clean-up.
 *
 * Every call into Cornerstone is guarded with class_exists()/method_exists(),
 * and the `pe_force_fallback` filter forces the direct path (for tests).
 */
final class DocumentGateway
{
    public const PATH_API      = 'cornerstone-api';
    public const PATH_FALLBACK = 'fallback';

    /**
     * Pro Extended document types => Cornerstone doc types.
     */
    public const DOC_TYPES = [
        'header'            => 'layout:header',
        'footer'            => 'layout:footer',
        'component'         => 'custom:component',
        'layout_single'     => 'layout:single',
        'layout_archive'    => 'layout:archive',
        'layout_single_wc'  => 'layout:single-wc',
        'layout_archive_wc' => 'layout:archive-wc',
    ];

    /**
     * Cornerstone doc types => post types.
     */
    public const POST_TYPES = [
        'layout:header'     => 'cs_header',
        'layout:footer'     => 'cs_footer',
        'custom:component'  => 'cs_global_block',
        'layout:single'     => 'cs_layout_single',
        'layout:archive'    => 'cs_layout_archive',
        'layout:single-wc'  => 'cs_layout_single_wc',
        'layout:archive-wc' => 'cs_layout_archive_wc',
    ];

    /**
     * Options that hold Cornerstone's cached assignment rules.
     */
    public const ASSIGNMENT_CACHE_OPTIONS = [
        'cs_assignment_cache_layout_header',
        'cs_assignment_cache_layout_footer',
        'cs_assignment_cache_layout_single',
        'cs_assignment_cache_layout_archive',
        'cs_assignment_cache_layout_single-wc',
        'cs_assignment_cache_layout_archive-wc',
    ];

    /**
     * Post meta Cornerstone deletes with $wpdb when it purges, bypassing the
     * object cache.
     */
    public const GENERATED_META_KEYS = ['_cs_generated_styles', '_cs_generated_tss', '_cs_component_map'];

    private const DOCUMENT_CLASS     = 'Themeco\\Cornerstone\\Documents\\Document';
    private const GLOBAL_BLOCK_CLASS = 'Themeco\\Cornerstone\\Documents\\GlobalBlock';
    private const CONTENT_CLASS      = 'Themeco\\Cornerstone\\Documents\\Content';

    /**
     * Settings Cornerstone 7.9.4 persists in post_content, with their class
     * defaults. Used when the Document API is unavailable.
     */
    private const STORED_DEFAULTS = [
        'layout:header' => [
            'customCSS'              => '',
            'customJS'               => '',
            'assignments'            => [],
            'layout_type'            => '',
            'assignment_priority'    => 0,
            'general_featured_image' => '',
            'multi_region'           => false,
        ],
        'layout:footer' => [
            'customCSS'              => '',
            'customJS'               => '',
            'assignments'            => [],
            'layout_type'            => '',
            'assignment_priority'    => 0,
            'general_featured_image' => '',
        ],
        'theme' => [
            'customCSS'           => '',
            'customJS'            => '',
            'assignments'         => [],
            'layout_type'         => 'single',
            'assignment_priority' => 0,
            'header_enabled'      => true,
            'footer_enabled'      => true,
        ],
        'custom:component' => [
            'customCSS'           => '',
            'customJS'            => '',
            'library_group'       => '',
            'document_visibility' => '',
        ],
    ];

    private const REGIONS = [
        'layout:header' => ['top', 'right', 'bottom', 'left'],
        'layout:footer' => ['footer'],
        'theme'         => ['layout'],
    ];

    /**
     * Settings that mirror post fields rather than being document data.
     */
    private const IDENTITY_KEYS = [
        'layout'    => ['general_title'],
        'component' => ['general_post_title', 'general_post_name'],
    ];

    /**
     * Elements of a new, empty component document (Component::getInitialElements()).
     */
    private const INITIAL_COMPONENT_ELEMENTS = [
        'e0' => ['_type' => 'root', '_modules' => ['e1']],
        'e1' => ['_type' => 'region', '_region' => 'content', '_modules' => []],
    ];

    /** @var array<string, mixed>|null Registry memo, reset by every write and purge. */
    private ?array $registry = null;

    // ─── Availability ────────────────────────────────────────────────────────

    /**
     * Whether the `pe_force_fallback` filter asks for the direct write path.
     */
    public function fallbackForced(): bool
    {
        return (bool) apply_filters('pe_force_fallback', false);
    }

    /**
     * Whether Cornerstone's Document API can be used for writes.
     */
    public function apiAvailable(): bool
    {
        if ($this->fallbackForced()) {
            return false;
        }

        return function_exists('cornerstone')
            && class_exists(self::DOCUMENT_CLASS)
            && method_exists(self::DOCUMENT_CLASS, 'create')
            && method_exists(self::DOCUMENT_CLASS, 'locate');
    }

    /**
     * Which Cornerstone entry points this adapter can reach, for health checks.
     *
     * @return array<string, bool>
     */
    public function capabilities(): array
    {
        $components  = $this->service('Components');
        $assignments = $this->service('Assignments');
        $options     = $this->service('ThemeOptions');
        $resolver    = $this->service('Resolver');
        $admin       = $this->service('Admin');

        return [
            'document_api'           => function_exists('cornerstone') && class_exists(self::DOCUMENT_CLASS) && method_exists(self::DOCUMENT_CLASS, 'create') && method_exists(self::DOCUMENT_CLASS, 'locate'),
            'cleanup_generated'      => function_exists('cornerstone_cleanup_generated_styles'),
            'clear_assignments'      => $assignments !== null && method_exists($assignments, 'clear_cached_assignments'),
            'component_registry'     => $components !== null && method_exists($components, 'loadCache'),
            'purge_components'       => $components !== null && method_exists($components, 'purge_cache'),
            'global_css_key'         => $options !== null && method_exists($options, 'get_global_css_key'),
            'theme_option_save'      => $options !== null && method_exists($options, 'update_value') && method_exists($options, 'commit'),
            'resolver_cache'         => $resolver !== null && method_exists($resolver, 'clearDocumentCache'),
            'builder_edit_url'       => $admin !== null && method_exists($admin, 'get_edit_url'),
            'force_fallback_enabled' => $this->fallbackForced(),
        ];
    }

    // ─── Document types ──────────────────────────────────────────────────────

    /**
     * Map a Pro Extended document type (e.g. "header") to a Cornerstone doc type.
     *
     * @throws \InvalidArgumentException
     */
    public function docTypeForType(string $type): string
    {
        if (! isset(self::DOC_TYPES[$type])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown document type "%s". Allowed: %s.',
                $type,
                implode(', ', $this->availableTypes())
            ));
        }

        return self::DOC_TYPES[$type];
    }

    /**
     * Document types whose post types are registered on this site.
     *
     * @return string[]
     */
    public function availableTypes(): array
    {
        $types = [];

        foreach (self::DOC_TYPES as $type => $docType) {
            $postType = self::POST_TYPES[$docType];

            // The WooCommerce layout types only exist while WooCommerce is active.
            if (str_ends_with($type, '_wc') && ! post_type_exists($postType)) {
                continue;
            }

            $types[] = $type;
        }

        return $types;
    }

    public function postTypeForDocType(string $docType): ?string
    {
        return self::POST_TYPES[$docType] ?? null;
    }

    public function isComponentDocType(string $docType): bool
    {
        return $docType === 'custom:component';
    }

    public function isHeaderOrFooterDocType(string $docType): bool
    {
        return $docType === 'layout:header' || $docType === 'layout:footer';
    }

    public function isThemeLayoutDocType(string $docType): bool
    {
        return str_starts_with($docType, 'layout:') && ! $this->isHeaderOrFooterDocType($docType);
    }

    /**
     * The `layout_type` setting a new document of this type is stored with.
     */
    public function layoutTypeFor(string $docType): string
    {
        if ($this->isHeaderOrFooterDocType($docType)) {
            return 'any'; // What the builder stores (Resolver's assignmentContext).
        }

        return substr($docType, strlen('layout:'));
    }

    /**
     * The Cornerstone doc type of a stored document, or null for posts that are
     * not Cornerstone layout documents.
     */
    public function docTypeForPost(\WP_Post $post): ?string
    {
        foreach (self::POST_TYPES as $docType => $postType) {
            if ($post->post_type === $postType) {
                return $docType;
            }
        }

        if ($post->post_type === 'cs_layout') {
            $data = Json::decodeStored($post->post_content);
            $layoutType = is_array($data) ? (string) ($data['settings']['layout_type'] ?? '') : '';

            return $layoutType !== '' ? 'layout:' . $layoutType : 'layout:unknown';
        }

        if (in_array($post->post_type, ['page', 'post'], true) || post_type_exists($post->post_type)) {
            return 'content:' . $post->post_type;
        }

        return null;
    }

    /**
     * Whether a component post still holds a legacy (pre-component) global
     * block. Cornerstone treats any content that does not decode as JSON as
     * legacy (Component::load()).
     */
    public function isLegacyGlobalBlock(\WP_Post $post): bool
    {
        return $post->post_type === 'cs_global_block'
            && $post->post_content !== ''
            && Json::decodeStored($post->post_content) === null;
    }

    /**
     * Regions a layout document stores.
     *
     * @return string[]
     */
    public function regionsFor(string $docType): array
    {
        if (isset(self::REGIONS[$docType])) {
            return self::REGIONS[$docType];
        }

        return $this->isThemeLayoutDocType($docType) ? self::REGIONS['theme'] : [];
    }

    /**
     * Regions Cornerstone renders for a layout document: the document class's
     * getRegions(), passed through `cs_layout_type_required_regions` the way
     * Layout::transformElements() does. A region outside this list is stored
     * but never loaded, so its elements are silently lost.
     *
     * Falls back to the known names (regionsFor()) when the Document API is
     * unavailable.
     *
     * @return string[]
     */
    public function renderedRegions(string $docType): array
    {
        if (! $this->isComponentDocType($docType) && $this->apiAvailable()) {
            try {
                $class = '\\' . self::DOCUMENT_CLASS;
                $doc = $class::create($docType);

                if (is_object($doc) && method_exists($doc, 'getRegions')) {
                    $regions = apply_filters('cs_layout_type_required_regions', (array) $doc->getRegions(), $doc);
                    $regions = array_values(array_filter(
                        array_map('strval', is_array($regions) ? $regions : []),
                        static fn(string $region): bool => $region !== ''
                    ));

                    if ($regions !== []) {
                        return $regions;
                    }
                }
            } catch (\Throwable) {
                // Fall back to the known names below.
            }
        }

        return $this->regionsFor($docType);
    }

    /**
     * Refuse a regions map that names a region the document type does not
     * render. A missing region is fine (Cornerstone fills it empty); an extra
     * one is stored and never shown, which is how a single layout written with
     * a "content" region ended up assigned site-wide and empty.
     *
     * @param  array<string|int, mixed> $regions  The regions map being written.
     * @param  string[]                 $rendered renderedRegions() for the type.
     *
     * @throws \InvalidArgumentException
     */
    public static function assertRegionsRendered(string $docType, array $regions, array $rendered): void
    {
        $unused = array_values(array_diff(array_map('strval', array_keys($regions)), $rendered));

        if ($unused === []) {
            return;
        }

        $quote = static fn(array $names): string => implode(', ', array_map(static fn(string $name): string => '"' . $name . '"', $names));

        throw new \InvalidArgumentException(sprintf(
            '%s %s not rendered by a %s document, so %s elements would be saved and never shown. Its regions are: %s. Nothing was written.',
            count($unused) === 1 ? 'Region' : 'Regions',
            $quote($unused) . (count($unused) === 1 ? ' is' : ' are'),
            $docType,
            count($unused) === 1 ? 'its' : 'their',
            $rendered === [] ? '(none)' : $quote($rendered)
        ));
    }

    /**
     * Setting keys Cornerstone persists in post_content for this doc type.
     *
     * @return string[]
     */
    public function storedSettingKeys(string $docType): array
    {
        return array_keys($this->storedDefaults($docType));
    }

    /**
     * Default settings of a new document, as Cornerstone reports them
     * (`Document::create($docType)->settings()`, including settings added by
     * filters).
     *
     * @return array<string, mixed>
     */
    public function defaultSettings(string $docType): array
    {
        if ($this->apiAvailable()) {
            try {
                $class = '\\' . self::DOCUMENT_CLASS;
                $settings = $class::create($docType)->settings();

                if (is_array($settings)) {
                    if (array_key_exists('layout_type', $settings)) {
                        $settings['layout_type'] = $this->isThemeLayoutDocType($docType)
                            ? $this->layoutTypeFor($docType)
                            : $settings['layout_type'];
                    }

                    return $settings;
                }
            } catch (\Throwable) {
                // Fall back to the known defaults below.
            }
        }

        return $this->storedDefaults($docType);
    }

    /**
     * Defaults of only the settings Cornerstone stores in post_content, with
     * the identity settings (title/slug mirrors) removed.
     *
     * @return array<string, mixed>
     */
    public function storedDefaults(string $docType): array
    {
        if ($this->apiAvailable()) {
            try {
                $class = '\\' . self::DOCUMENT_CLASS;
                $fresh = $class::create($docType);

                if (is_object($fresh) && method_exists($fresh, 'defaultSettings')) {
                    $defaults = $fresh->defaultSettings();

                    if (is_array($defaults)) {
                        $defaults = array_diff_key($defaults, array_flip($this->identityKeys($docType)));

                        if ($this->isThemeLayoutDocType($docType)) {
                            $defaults['layout_type'] = $this->layoutTypeFor($docType);
                        }

                        return $defaults;
                    }
                }
            } catch (\Throwable) {
                // Fall back to the known defaults below.
            }
        }

        return $this->knownStoredDefaults($docType);
    }

    // ─── Document writes ─────────────────────────────────────────────────────

    /**
     * Refuse document writes for users without `unfiltered_html`.
     *
     * WordPress runs its HTML filter (kses) over post_content for those users,
     * which damages the stored JSON; it also stops a user who may not add
     * unfiltered HTML from adding scripts through layout data.
     *
     * @throws \RuntimeException
     */
    public function assertCanWriteDocuments(): void
    {
        if (! current_user_can('unfiltered_html')) {
            throw new \RuntimeException(
                'Writing Cornerstone documents requires the unfiltered_html capability. WordPress filters post_content for users without it, which damages the stored layout. Use an administrator account (and pass --user=<ID> on the command line).'
            );
        }
    }

    /**
     * Create a Cornerstone document.
     *
     * @param  array{title?: string, slug?: string, settings?: array<string, mixed>, elements?: array<mixed>|null} $update
     * @return array{path: string, id: int, post_type: string, doc_type: string, title: string, warnings: string[]}
     */
    public function createDocument(string $docType, array $update): array
    {
        $this->assertCanWriteDocuments();

        $postType = $this->postTypeForDocType($docType);

        if ($postType === null || ! post_type_exists($postType)) {
            throw new \InvalidArgumentException(sprintf('Documents of type "%s" cannot be created on this site.', $docType));
        }

        $title    = (string) ($update['title'] ?? '');
        $slug     = (string) ($update['slug'] ?? '');
        $settings = (array) ($update['settings'] ?? []);
        $elements = $update['elements'] ?? null;
        $warnings = [];

        if (str_starts_with($docType, 'layout:')) {
            $settings['layout_type'] = $this->layoutTypeFor($docType);
        }

        if ($this->apiAvailable()) {
            $metaIds = $this->postsWithCachedMeta();
            $doc = null;

            try {
                $class = '\\' . self::DOCUMENT_CLASS;
                $doc = $class::create($docType);

                $payload = ['title' => $title];

                if ($this->isComponentDocType($docType)) {
                    if ($slug !== '') {
                        $settings['general_post_name'] = $slug;
                    }

                    if ($elements !== null) {
                        $payload['elements'] = $elements;
                    }
                } else {
                    $payload['elements'] = $elements ?? array_fill_keys(
                        method_exists($doc, 'getRegions') ? (array) $doc->getRegions() : $this->regionsFor($docType),
                        []
                    );
                }

                $payload['settings'] = $settings;

                $updated = $doc->update($payload);

                if (is_object($updated)) {
                    $doc = $updated;
                }

                $doc->save();

                $id = (int) $doc->id();
                $this->clearResolverCache($id);

                return $this->createdResult(self::PATH_API, $id, $postType, $docType, $warnings);
            } catch (\Throwable $e) {
                $createdId = is_object($doc) && method_exists($doc, 'id') && is_int($doc->id()) ? $doc->id() : 0;
                $created = $createdId > 0 ? get_post($createdId) : null;

                if ($created instanceof \WP_Post && $created->post_type === $postType) {
                    // The post exists, so creating it again would duplicate it.
                    $this->fallbackSideEffects($created);
                    $warnings[] = 'Cornerstone reported an error after saving the document (' . $e->getMessage() . '); caches were cleared directly.';

                    return $this->createdResult(self::PATH_API, $createdId, $postType, $docType, $warnings);
                }

                $warnings[] = 'Cornerstone\'s document API failed (' . $e->getMessage() . '); the document was written directly.';
            } finally {
                $this->clearMetaCache($metaIds);
                $this->forgetRegistry();
            }
        }

        $shape = $this->buildStoredShape($docType, $title, $slug, $settings, $elements, null);

        $postarr = [
            'post_type'    => $postType,
            'post_status'  => 'tco-data',
            'post_title'   => wp_slash($title),
            'post_content' => wp_slash(Json::encodeCs($shape)),
        ];

        if ($this->isComponentDocType($docType) && $slug !== '') {
            $postarr['post_name'] = sanitize_title($slug);
        }

        $id = $this->withJsonContentGuard(static fn() => wp_insert_post($postarr, true));

        if (is_wp_error($id) || ! is_int($id) || $id === 0) {
            throw new \RuntimeException('Failed to create the document: ' . (is_wp_error($id) ? $id->get_error_message() : 'unknown error'));
        }

        $post = get_post($id);

        if ($post instanceof \WP_Post) {
            $this->fallbackSideEffects($post);
        }

        return $this->createdResult(self::PATH_FALLBACK, $id, $postType, $docType, $warnings);
    }

    /**
     * Update part of a document: merge settings, and optionally change the
     * title, slug (components) or elements. Settings that are not given keep
     * their current values.
     *
     * @param  array{title?: string, slug?: string, settings?: array<string, mixed>, elements?: array<mixed>} $update
     * @return array{path: string, id: int, warnings: string[]}
     */
    public function updateDocument(int $id, array $update): array
    {
        $post = $this->requireDocumentPost($id);
        $this->assertCanWriteDocuments();

        $docType  = (string) $this->docTypeForPost($post);
        $warnings = [];

        if ($this->isLegacyGlobalBlock($post)) {
            throw new \RuntimeException(sprintf(
                'Document %d is a legacy global block. Open it in Cornerstone once to convert it to a component document.',
                $id
            ));
        }

        $settings = (array) ($update['settings'] ?? []);

        if (isset($update['slug']) && $this->isComponentDocType($docType)) {
            $settings['general_post_name'] = sanitize_title((string) $update['slug']);
        }

        if ($post->post_type !== 'cs_layout' && $this->apiAvailable()) {
            $metaIds = $this->postsWithCachedMeta();

            try {
                $class = '\\' . self::DOCUMENT_CLASS;
                $doc = $class::locate($post);

                if (! is_object($doc)) {
                    throw new \RuntimeException('Cornerstone could not load the document.');
                }

                $payload = ['title' => (string) ($update['title'] ?? $post->post_title)];

                if ($settings !== []) {
                    $payload['settings'] = $settings;
                }

                if (array_key_exists('elements', $update)) {
                    $payload['elements'] = $update['elements'];
                } else {
                    // Cornerstone migrates elements when it loads a document, so
                    // saving the loaded copy would rewrite them. Save the stored
                    // elements as they are instead.
                    $storedElements = $this->storedElements($post, $docType);

                    if ($storedElements !== null) {
                        $payload['elements'] = $storedElements;
                    }
                }

                $updated = $doc->update($payload);

                if (is_object($updated)) {
                    $doc = $updated;
                }

                $this->assertSaveKeepsPostType($doc, $post);
                $doc->save();
                $this->clearResolverCache($id);

                return ['path' => self::PATH_API, 'id' => $id, 'warnings' => $warnings];
            } catch (\Throwable $e) {
                $warnings[] = 'Cornerstone\'s document API failed (' . $e->getMessage() . '); the document was written directly.';
                $post = get_post($id) ?: $post;
            } finally {
                $this->clearMetaCache($metaIds);
                $this->forgetRegistry();
            }
        }

        if ($post->post_type === 'cs_layout') {
            $warnings[] = 'Legacy cs_layout document written directly so its post type is not changed. Open it in Cornerstone to migrate it.';
        }

        $stored = Json::decodeStored($post->post_content) ?? [];
        $currentSettings = is_array($stored['settings'] ?? null) ? $stored['settings'] : [];
        $title = (string) ($update['title'] ?? $post->post_title);
        $slug  = (string) ($settings['general_post_name'] ?? $post->post_name);

        if ($this->isComponentDocType($docType)) {
            $merged = array_merge($currentSettings, $settings, [
                'general_post_title' => $title,
                'general_post_name'  => $slug,
            ]);
            $shape = [
                'elements' => array_key_exists('elements', $update) ? $update['elements'] : ($stored['elements'] ?? self::INITIAL_COMPONENT_ELEMENTS),
                'settings' => $merged,
            ];
        } else {
            $shape = [
                'settings' => $this->filterStoredSettings($docType, array_merge($currentSettings, $settings)),
                'regions'  => array_key_exists('elements', $update) ? $update['elements'] : ($stored['regions'] ?? []),
            ];
        }

        $fields = [];

        if ($title !== $post->post_title) {
            $fields['post_title'] = wp_slash($title);
        }

        if ($this->isComponentDocType($docType) && $slug !== $post->post_name) {
            $fields['post_name'] = $slug;
        }

        $this->writeRaw($post, Json::encodeCs($shape), $fields);
        $this->fallbackSideEffects(get_post($id) ?: $post);

        return ['path' => self::PATH_FALLBACK, 'id' => $id, 'warnings' => $warnings];
    }

    /**
     * Replace a document's elements and settings (what deploy_layout does).
     *
     * Settings the payload leaves out go back to their defaults. The title,
     * slug and anything Cornerstone keeps outside post_content (such as a
     * document's custom scripts and styles) stay as they are, and a single or
     * archive layout keeps its stored `layout_type` so its post type cannot
     * change. When `$settings` is null the current settings are kept.
     *
     * @param  array<mixed>              $elements Regions map (layouts) or flat element map (components).
     * @param  array<string, mixed>|null $settings
     * @return array{path: string, id: int, warnings: string[]}
     */
    public function replaceDocument(int $id, array $elements, ?array $settings): array
    {
        $post = $this->requireDocumentPost($id);
        $this->assertCanWriteDocuments();

        $docType  = (string) $this->docTypeForPost($post);
        $warnings = [];

        if ($this->isLegacyGlobalBlock($post)) {
            throw new \RuntimeException(sprintf('Document %d is a legacy global block; write it with the direct path.', $id));
        }

        if ($settings === null) {
            $warnings[] = 'The layout data has no "settings" object, so the document kept its current settings.';
        }

        if ($post->post_type !== 'cs_layout' && $this->apiAvailable()) {
            $metaIds = $this->postsWithCachedMeta();

            try {
                $class = '\\' . self::DOCUMENT_CLASS;
                $loaded = $class::locate($post);

                if (! is_object($loaded) || is_a($loaded, self::GLOBAL_BLOCK_CLASS)) {
                    throw new \RuntimeException('Cornerstone could not load the document.');
                }

                $loadedSettings = (array) $loaded->settings();
                $fresh = $class::create($loaded->getDocType());

                // Start from a document that has only default settings, so any
                // key the payload leaves out really returns to its default.
                // Cornerstone uses the same create()->setPost() pattern when it
                // converts a legacy global block.
                $fresh->setPost($post);

                $freshSettings = (array) $fresh->settings();
                $classDefaults = method_exists($fresh, 'defaultSettings') ? (array) $fresh->defaultSettings() : [];

                // Settings that filters add (stored outside post_content, e.g.
                // DocumentAssets' customScripts/customStyles) carry over.
                $carry = [];

                foreach (array_keys(array_diff_key($freshSettings, $classDefaults)) as $key) {
                    if (array_key_exists($key, $loadedSettings)) {
                        $carry[$key] = $loadedSettings[$key];
                    }
                }

                $payloadSettings = $this->resolveReplacementSettings($docType, $settings, $loadedSettings, $warnings);
                $identity = $this->isComponentDocType($docType)
                    ? ['general_post_title' => $post->post_title, 'general_post_name' => $post->post_name]
                    : [];

                $updated = $fresh->update([
                    'title'    => $post->post_title,
                    'settings' => array_merge($carry, $payloadSettings, $identity),
                    'elements' => $elements,
                ]);

                $doc = is_object($updated) ? $updated : $fresh;
                $this->assertSaveKeepsPostType($doc, $post);
                $doc->save();
                $this->clearResolverCache($id);

                return ['path' => self::PATH_API, 'id' => $id, 'warnings' => $warnings];
            } catch (\Throwable $e) {
                $warnings[] = 'Cornerstone\'s document API failed (' . $e->getMessage() . '); the document was written directly.';
                $post = get_post($id) ?: $post;
            } finally {
                $this->clearMetaCache($metaIds);
                $this->forgetRegistry();
            }
        }

        if ($post->post_type === 'cs_layout') {
            $warnings[] = 'Legacy cs_layout document written directly so its post type is not changed. Open it in Cornerstone to migrate it.';
        }

        $stored = Json::decodeStored($post->post_content) ?? [];
        $currentSettings = is_array($stored['settings'] ?? null) ? $stored['settings'] : [];
        $payloadSettings = $this->resolveReplacementSettings($docType, $settings, $currentSettings, $warnings);

        if ($this->isComponentDocType($docType)) {
            $base = $settings === null ? [] : $this->knownStoredDefaults($docType);
            $shape = [
                'elements' => $elements,
                'settings' => array_merge($base, $payloadSettings, [
                    'general_post_title' => $post->post_title,
                    'general_post_name'  => $post->post_name,
                ]),
            ];
        } else {
            $base = $settings === null ? [] : $this->knownStoredDefaults($docType);
            $shape = [
                'settings' => $this->filterStoredSettings($docType, array_merge($base, $payloadSettings)),
                'regions'  => $elements,
            ];
        }

        $this->writeRaw($post, Json::encodeCs($shape));
        $this->fallbackSideEffects(get_post($id) ?: $post);

        return ['path' => self::PATH_FALLBACK, 'id' => $id, 'warnings' => $warnings];
    }

    /**
     * Write raw post_content to a document (legacy global blocks, restores).
     *
     * @param  array<string, mixed> $fields Extra, already-slashed post fields.
     *
     * @throws \RuntimeException
     */
    public function writeRaw(\WP_Post $post, string $content, array $fields = []): void
    {
        $postarr = array_merge($fields, [
            'ID'           => $post->ID,
            'post_content' => wp_slash($content),
        ]);

        $result = $this->withJsonContentGuard(static fn() => wp_update_post($postarr, true));

        if (is_wp_error($result) || $result === 0) {
            throw new \RuntimeException(sprintf(
                'Failed to write document %d: %s',
                $post->ID,
                is_wp_error($result) ? $result->get_error_message() : 'unknown error'
            ));
        }
    }

    /**
     * Insert a new post whose post_content is copied as-is (translations).
     *
     * The same path as writeRaw(): WordPress unslashes whatever it is given,
     * so the content is slashed once here, and the insert runs inside the
     * JSON-content guard. Without the slash a stored `\"` or `\n` in a
     * header's JSON loses its backslash and the copy no longer decodes.
     *
     * @param  array<string, mixed> $fields Other post fields, already slashed.
     *
     * @throws \RuntimeException
     */
    public function insertRaw(string $content, array $fields): int
    {
        $postarr = self::rawPostarr($content, $fields);

        $id = $this->withJsonContentGuard(static fn() => wp_insert_post($postarr, true));

        if (is_wp_error($id) || ! is_int($id) || $id === 0) {
            throw new \RuntimeException('Failed to create the post: ' . (is_wp_error($id) ? $id->get_error_message() : 'unknown error'));
        }

        return $id;
    }

    /**
     * The post array insertRaw() hands to WordPress: the fields as given, with
     * post_content slashed.
     *
     * @param  array<string, mixed> $fields Already slashed.
     * @return array<string, mixed>
     */
    public static function rawPostarr(string $content, array $fields): array
    {
        return array_merge($fields, ['post_content' => wp_slash($content)]);
    }

    /**
     * Run the side effects of a document save after its post_content was
     * written directly (restores, legacy blocks): fire Cornerstone's save
     * hooks for the reloaded document when possible, otherwise clear its
     * caches directly.
     *
     * @return array{path: string}
     */
    public function afterRawWrite(\WP_Post $post): array
    {
        clean_post_cache($post->ID);
        $this->clearResolverCache($post->ID);

        if ($this->apiAvailable()) {
            $metaIds = $this->postsWithCachedMeta();

            try {
                $class = '\\' . self::DOCUMENT_CLASS;
                $doc = $class::locate($post->ID);

                if (is_object($doc) && method_exists($doc, 'type')) {
                    do_action('cs_save_document', $doc);
                    do_action('cs_save_' . $doc->type(), $post->ID);

                    if ($doc->type() === 'component') {
                        do_action('cs_purge_tmp');
                    }

                    delete_post_meta($post->ID, '_cs_generated_tss');
                    delete_post_meta($post->ID, '_cs_generated_styles');
                    $this->clearResolverCache($post->ID);

                    return ['path' => self::PATH_API];
                }
            } catch (\Throwable) {
                // Clear the caches directly below.
            } finally {
                $this->clearMetaCache($metaIds);
                $this->forgetRegistry();
            }
        }

        $this->fallbackSideEffects($post);

        return ['path' => self::PATH_FALLBACK];
    }

    /**
     * Save a content post's (page, post, ...) elements the way the builder
     * does.
     *
     * Document::save() runs Content::updateElements(), which stores the
     * elements, clears `_cornerstone_override`, fires
     * `cornerstone_before_save_content` and `cornerstone_after_save_content`,
     * and rebuilds post_content in the site's storage mode (rendered HTML or
     * [cs_content] shortcodes); the save then fires `cs_save_document` once.
     *
     * Returns null when the caller should write directly instead: the API is
     * unavailable or forced off, the post type is not registered, or
     * Cornerstone failed before it stored anything. The reason is added to
     * $warnings.
     *
     * @param  array<int, mixed> $elements
     * @param  string[]          $warnings
     * @return array{path: string}|null
     */
    public function savePage(int $id, array $elements, array &$warnings): ?array
    {
        if (! $this->apiAvailable() || ! class_exists(self::CONTENT_CLASS)) {
            return null;
        }

        $post = get_post($id);

        if (! $post instanceof \WP_Post) {
            throw new \InvalidArgumentException(sprintf('Post %d does not exist.', $id));
        }

        if (! post_type_exists($post->post_type)) {
            $warnings[] = sprintf('Post type "%s" is not registered, so the page was written directly.', $post->post_type);

            return null;
        }

        $previousPost = $GLOBALS['post'] ?? null;
        $documentSaves = did_action('cs_save_document');
        $contentSaves = did_action('cornerstone_after_save_content');
        $doc = null;

        // Cornerstone renders post_content from its per-request document cache,
        // so a copy of this page loaded earlier in the request must go first.
        $this->clearResolverCache($id);

        try {
            $class = '\\' . self::DOCUMENT_CLASS;
            $doc = $class::locate($id);

            if (! is_a($doc, self::CONTENT_CLASS) || ! method_exists($doc, 'updateElements')) {
                throw new \RuntimeException('Cornerstone did not load the post as content');
            }

            $doc->update([
                'elements' => $elements,
                'settings' => $this->slashedPostFields($post),
            ]);

            $result = $doc->save();

            if (is_wp_error($result)) {
                throw new \RuntimeException($result->get_error_message());
            }
        } catch (\Throwable $e) {
            if (did_action('cornerstone_after_save_content') <= $contentSaves) {
                $warnings[] = 'Cornerstone\'s document API failed (' . $e->getMessage() . '); the page was written directly.';

                return null;
            }

            // The elements and post_content were stored; a later step (the
            // featured image, or reloading the saved document) failed.
            $warnings[] = 'Cornerstone reported an error after saving the page (' . $e->getMessage() . ').';

            if (did_action('cs_save_document') <= $documentSaves && is_object($doc)) {
                do_action('cs_save_document', $doc);
            }
        } finally {
            $GLOBALS['post'] = $previousPost;
            $this->clearResolverCache($id);
        }

        $this->touchLastSave($id);

        return ['path' => self::PATH_API];
    }

    /**
     * Rebuild a content post's post_content from its stored elements in the
     * site's storage mode, the way Cornerstone's storage migration does
     * (Content::updateElements() without rewriting the stored elements).
     *
     * Returns null when Cornerstone cannot do it; the caller then renders the
     * HTML itself.
     *
     * @return array{path: string}|null
     */
    public function rebuildPageContent(int $id): ?array
    {
        if (! $this->apiAvailable() || ! class_exists(self::CONTENT_CLASS)) {
            return null;
        }

        $post = get_post($id);

        if (! $post instanceof \WP_Post || ! post_type_exists($post->post_type)) {
            return null;
        }

        $elements = Json::decodeStored(get_post_meta($id, '_cornerstone_data', true));
        $settings = Json::decodeStored(get_post_meta($id, '_cornerstone_settings', true)) ?? [];

        if ($elements === null) {
            return null;
        }

        $previousPost = $GLOBALS['post'] ?? null;
        $this->clearResolverCache($id);

        try {
            $class = '\\' . self::DOCUMENT_CLASS;
            $doc = $class::locate($id);

            if (! is_a($doc, self::CONTENT_CLASS) || ! method_exists($doc, 'updateElements')) {
                return null;
            }

            $result = $doc->updateElements($elements, $settings, false);

            return $result === true ? ['path' => self::PATH_API] : null;
        } catch (\Throwable) {
            return null;
        } finally {
            $GLOBALS['post'] = $previousPost;
            $this->clearResolverCache($id);
        }
    }

    /**
     * Merge settings into a page's stored Cornerstone settings.
     *
     * A page keeps its settings in `_cornerstone_settings` beside its elements,
     * not in post_content like a header or layout, so this goes through
     * Content::updateElements() with the elements unchanged — the same call the
     * builder makes when only the settings panel changed — and writes the meta
     * directly if that is unavailable.
     *
     * @param  array<string, mixed> $settings
     * @return array{path: string, before: array<string, mixed>, after: array<string, mixed>}
     */
    public function updatePageSettings(int $id, array $settings): array
    {
        $post = get_post($id);

        if (! $post instanceof \WP_Post) {
            throw new \InvalidArgumentException(sprintf('Post %d does not exist.', $id));
        }

        $before = $this->readPageSettings($id);
        $after = array_merge($before, $settings);

        // Cornerstone writes page settings as serialized meta and then rebuilds
        // post_content with them, which is what Content::save() does around its
        // own updateElements() call. Doing only the first would leave the
        // rendered page carrying the old settings until the next save.
        if (function_exists('cs_update_serialized_post_meta')) {
            cs_update_serialized_post_meta($id, '_cornerstone_settings', $after, '', false, 'cs_content_update_serialized_content');
        } else {
            update_post_meta($id, '_cornerstone_settings', wp_slash((string) wp_json_encode($after)));
        }

        $path = self::PATH_FALLBACK;
        $elements = Json::decodeStored(get_post_meta($id, '_cornerstone_data', true));

        if ($elements !== null && ! $this->fallbackForced()) {
            $previousPost = $GLOBALS['post'] ?? null;
            $this->clearResolverCache($id);

            try {
                $class = '\\' . self::DOCUMENT_CLASS;
                $doc = $class::locate($id);

                if (is_a($doc, self::CONTENT_CLASS) && method_exists($doc, 'updateElements')
                    && $doc->updateElements($elements, $after) === true) {
                    $path = self::PATH_API;
                }
            } catch (\Throwable) {
                // The meta is written either way; the rendered content catches
                // up on the next save.
            } finally {
                $GLOBALS['post'] = $previousPost;
                $this->clearResolverCache($id);
            }
        }

        $this->firePageSaved($id);

        return ['path' => $path, 'before' => $before, 'after' => $after];
    }

    /**
     * A page's stored Cornerstone settings.
     *
     * @return array<string, mixed>
     */
    public function readPageSettings(int $id): array
    {
        if (function_exists('cs_get_serialized_post_meta')) {
            $stored = cs_get_serialized_post_meta($id, '_cornerstone_settings', true);

            if (is_array($stored)) {
                return $stored;
            }
        }

        $stored = Json::decodeStored(get_post_meta($id, '_cornerstone_settings', true));

        return is_array($stored) ? $stored : [];
    }

    /**
     * After a page's Cornerstone data is written: fire `cs_save_document` the
     * way the builder does (its listeners refresh the `cs_last_save` option,
     * the Google Fonts request cache and the document's asset meta, which is
     * written back unchanged), and set the page's `_cs_last_save` meta, which
     * Cornerstone only sets during its own REST requests.
     *
     * @return array{path: string}
     */
    public function firePageSaved(int $id): array
    {
        $this->clearResolverCache($id);

        if ($this->apiAvailable()) {
            try {
                $class = '\\' . self::DOCUMENT_CLASS;
                $doc = $class::locate($id);

                if (is_object($doc)) {
                    do_action('cs_save_document', $doc);
                    $this->clearResolverCache($id);
                    $this->touchLastSave($id);

                    return ['path' => self::PATH_API];
                }
            } catch (\Throwable) {
                // Update the timestamps directly below.
            }
        }

        $this->touchLastSave($id);
        delete_option('x_cache_google_fonts_request');

        return ['path' => self::PATH_FALLBACK];
    }

    /**
     * Set the last-save timestamps the builder sets: the `cs_last_save`
     * option and the post's `_cs_last_save` meta.
     */
    public function touchLastSave(int $id): void
    {
        $now = current_time('mysql');
        update_option('cs_last_save', $now);
        update_post_meta($id, '_cs_last_save', $now);
    }

    // ─── Caches ──────────────────────────────────────────────────────────────

    /**
     * Purge generated styles and every other `cs_purge_tmp` cache.
     *
     * Cornerstone deletes the generated-style post meta with $wpdb, which
     * leaves stale copies in a persistent object cache, so the post meta cache
     * of every affected post is cleared as well.
     *
     * @return array{path: string, posts: int}
     */
    public function purgeGenerated(): array
    {
        $metaIds = $this->postsWithCachedMeta();

        if (! $this->fallbackForced() && function_exists('cornerstone_cleanup_generated_styles')) {
            cornerstone_cleanup_generated_styles();
            $path = self::PATH_API;
        } else {
            do_action('cs_purge_tmp');
            delete_post_meta_by_key('_cs_generated_styles');
            delete_post_meta_by_key('_cs_generated_tss');
            $path = self::PATH_FALLBACK;
        }

        $this->clearMetaCache($metaIds);
        $this->forgetRegistry();

        return ['path' => $path, 'posts' => count($metaIds)];
    }

    /**
     * Clear Cornerstone's cached assignment rules.
     *
     * @return array{path: string}
     */
    public function clearAssignments(): array
    {
        $assignments = $this->fallbackForced() ? null : $this->service('Assignments');

        if ($assignments !== null && method_exists($assignments, 'clear_cached_assignments')) {
            try {
                $assignments->clear_cached_assignments();

                return ['path' => self::PATH_API];
            } catch (\Throwable) {
                // Delete the options directly below.
            }
        }

        foreach (self::ASSIGNMENT_CACHE_OPTIONS as $option) {
            delete_option($option);
        }

        return ['path' => self::PATH_FALLBACK];
    }

    /**
     * Purge the component registry (the cached registry and every document's
     * component map).
     *
     * @return array{path: string}
     */
    public function purgeComponents(): array
    {
        $metaIds = $this->postsWithCachedMeta();
        $components = $this->fallbackForced() ? null : $this->service('Components');
        $path = self::PATH_FALLBACK;

        if ($components !== null && method_exists($components, 'purge_cache')) {
            try {
                $components->purge_cache();
                $path = self::PATH_API;
            } catch (\Throwable) {
                $path = self::PATH_FALLBACK;
            }
        }

        if ($path === self::PATH_FALLBACK) {
            delete_post_meta_by_key('_cs_component_map');
            delete_option('cs_component_cache');
        }

        $this->clearMetaCache($metaIds);
        $this->forgetRegistry();

        return ['path' => $path];
    }

    /**
     * The component registry the builder uses, plus duplicate `_c_id` errors.
     *
     * Cornerstone memoizes its registry for the rest of the request, so this
     * reads it with `loadCache()` (which rebuilds the stored cache when a save
     * has purged it). Cornerstone 7.9.4 never fills its own `errors` list, so
     * duplicates are detected here from the component documents.
     *
     * @return array{
     *     source: string,
     *     components: array<string, array<string, mixed>>,
     *     doc_settings: array<string, mixed>,
     *     virtual_index: array<string, mixed>,
     *     errors: string[]
     * }
     */
    public function componentRegistry(bool $refresh = false): array
    {
        if ($refresh) {
            $metaIds = $this->postsWithCachedMeta();
            do_action('cs_purge_tmp');

            if ($this->service('Components') === null) {
                delete_post_meta_by_key('_cs_component_map');
                delete_option('cs_component_cache');
            }

            $this->clearMetaCache($metaIds);
            $this->forgetRegistry();
        }

        if ($this->registry !== null) {
            return $this->registry;
        }

        $scan = $this->scanComponentDocuments();
        $source = 'pro-extended';
        $components = $scan['components'];
        $docSettings = $scan['doc_settings'];
        $virtual = [];
        $errors = [];

        // Always read the registry the builder uses, even when writes are forced
        // down the fallback path: that is what shows whether a fallback write
        // kept it fresh.
        $service = $this->service('Components');

        if ($service !== null && method_exists($service, 'loadCache')) {
            try {
                $cache = $service->loadCache();

                if (is_array($cache) && isset($cache[0]) && is_array($cache[0])) {
                    $components  = $cache[0];
                    $docSettings = is_array($cache[1] ?? null) ? $cache[1] : [];
                    $virtual     = is_array($cache[2] ?? null) ? $cache[2] : [];
                    $errors      = is_array($cache[3] ?? null) ? array_map('strval', $cache[3]) : [];
                    $source      = 'cornerstone';
                }
            } catch (\Throwable) {
                // Keep the scanned registry.
            }
        }

        return $this->registry = [
            'source'        => $source,
            'components'    => $components,
            'doc_settings'  => $docSettings,
            'virtual_index' => $virtual,
            'errors'        => array_values(array_unique(array_merge($errors, $scan['errors']))),
        ];
    }

    /**
     * Drop the memoized registry.
     */
    public function forgetRegistry(): void
    {
        $this->registry = null;
    }

    // ─── Global CSS and theme options ────────────────────────────────────────

    /**
     * The option that holds Global CSS (Theme Options → CSS).
     */
    public function globalCssKey(): string
    {
        $options = $this->service('ThemeOptions');

        if ($options !== null && method_exists($options, 'get_global_css_key')) {
            try {
                $key = $options->get_global_css_key();

                if (is_string($key) && $key !== '') {
                    return $key;
                }
            } catch (\Throwable) {
                // Use the fallback below.
            }
        }

        return 'x_custom_styles';
    }

    /**
     * Read a theme option the way Cornerstone does.
     */
    public function getThemeOption(string $key): mixed
    {
        $options = $this->service('ThemeOptions');

        if ($options !== null && method_exists($options, 'get_value')) {
            try {
                return $options->get_value($key);
            } catch (\Throwable) {
                // Read the option directly below.
            }
        }

        return get_option($key, '');
    }

    /**
     * Save one theme option with the builder's sequence
     * (Save::save_theme_options) and purge generated styles.
     *
     * @return array{path: string, purge: array{path: string, posts: int}, warnings: string[]}
     */
    public function updateThemeOption(string $key, mixed $value): array
    {
        $warnings = [];
        $options = $this->fallbackForced() ? null : $this->service('ThemeOptions');

        if ($options !== null && method_exists($options, 'update_value') && method_exists($options, 'commit')) {
            try {
                do_action('cs_theme_options_before_save');
                $options->update_value($key, $value);
                $options->commit();
                do_action('cs_theme_options_after_save');

                return ['path' => self::PATH_API, 'purge' => $this->purgeGenerated(), 'warnings' => $warnings];
            } catch (\Throwable $e) {
                $warnings[] = 'Cornerstone\'s theme options API failed (' . $e->getMessage() . '); the option was written directly.';
            }
        }

        update_option($key, $value);
        delete_option('x_cache_google_fonts_request');

        return ['path' => self::PATH_FALLBACK, 'purge' => $this->purgeGenerated(), 'warnings' => $warnings];
    }

    /**
     * Cornerstone's font list (system fonts, plus Google fonts unless they are
     * disabled), keyed by font name. Null when Cornerstone is not loaded.
     *
     * @return array<string, array<string, mixed>>|null
     */
    public function fontCatalog(): ?array
    {
        $fonts = $this->service('GlobalFonts');

        if ($fonts === null || ! method_exists($fonts, 'font_data')) {
            return null;
        }

        try {
            $data = $fonts->font_data();

            return is_array($data) ? $data : null;
        } catch (\Throwable) {
            return null;
        }
    }

    // ─── Misc ────────────────────────────────────────────────────────────────

    /**
     * The builder URL for a document, when Cornerstone can build one.
     */
    public function editUrl(int $id): ?string
    {
        $admin = $this->service('Admin');

        if ($admin === null || ! method_exists($admin, 'get_edit_url')) {
            return null;
        }

        try {
            $url = $admin->get_edit_url($id);

            return is_string($url) && $url !== '' ? $url : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Other documents of the same type that claim an assignment condition at
     * the given priority.
     *
     * @return array<int, array{id: int, title: string}>
     */
    public function documentsClaiming(string $docType, string $condition, int $priority, int $excludeId = 0): array
    {
        $postType = $this->postTypeForDocType($docType);

        if ($postType === null || ! post_type_exists($postType)) {
            return [];
        }

        $posts = get_posts([
            'post_type'        => $postType,
            'post_status'      => 'tco-data',
            'posts_per_page'   => -1,
            'suppress_filters' => true,
            'orderby'          => 'ID',
            'order'            => 'ASC',
        ]);

        $matches = [];

        foreach ($posts as $post) {
            if ($post->ID === $excludeId) {
                continue;
            }

            $data = Json::decodeStored($post->post_content);
            $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];

            if ($this->isThemeLayoutDocType($docType) && ($settings['layout_type'] ?? '') !== $this->layoutTypeFor($docType)) {
                continue;
            }

            if ((int) ($settings['assignment_priority'] ?? 0) !== $priority) {
                continue;
            }

            foreach ((array) ($settings['assignments'] ?? []) as $rule) {
                if (is_array($rule) && ($rule['condition'] ?? null) === $condition) {
                    $matches[] = ['id' => $post->ID, 'title' => $post->post_title];
                    break;
                }
            }
        }

        return $matches;
    }

    // ─── Internal ────────────────────────────────────────────────────────────

    /**
     * Cornerstone service by name, or null when Cornerstone is not loaded.
     */
    private function service(string $name): ?object
    {
        if (! function_exists('cornerstone')) {
            return null;
        }

        try {
            $service = cornerstone($name);

            return is_object($service) ? $service : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The post fields Content::save() writes back, slashed.
     *
     * Content::save() hands them to wp_update_post() unslashed, and WordPress
     * unslashes what it is given, so a backslash in a title or excerpt would
     * be lost. Passing the current values slashed stores them unchanged.
     *
     * @return array<string, string>
     */
    private function slashedPostFields(\WP_Post $post): array
    {
        $fields = [];

        if (post_type_supports($post->post_type, 'title')) {
            $fields['general_post_title'] = wp_slash($post->post_title);
        }

        if (post_type_supports($post->post_type, 'excerpt')) {
            $fields['general_manual_excerpt'] = wp_slash($post->post_excerpt);
        }

        return $fields;
    }

    /**
     * @return string[]
     */
    private function identityKeys(string $docType): array
    {
        return $this->isComponentDocType($docType) ? self::IDENTITY_KEYS['component'] : self::IDENTITY_KEYS['layout'];
    }

    /**
     * @return array<string, mixed>
     */
    private function knownStoredDefaults(string $docType): array
    {
        if (isset(self::STORED_DEFAULTS[$docType])) {
            return self::STORED_DEFAULTS[$docType];
        }

        if ($this->isThemeLayoutDocType($docType)) {
            $defaults = self::STORED_DEFAULTS['theme'];
            $defaults['layout_type'] = $this->layoutTypeFor($docType);

            return $defaults;
        }

        return [];
    }

    /**
     * Keep only the settings Cornerstone stores for a layout, dropping nulls
     * (Layout::transformSaveData()).
     *
     * @param  array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function filterStoredSettings(string $docType, array $settings): array
    {
        $stored = [];

        foreach (array_keys($this->knownStoredDefaults($docType)) as $key) {
            if (isset($settings[$key])) {
                $stored[$key] = $settings[$key];
            }
        }

        return $stored;
    }

    /**
     * Settings for a replace: the payload (or the current settings when the
     * payload has none), without identity keys, with the layout type pinned.
     *
     * @param  array<string, mixed>|null $settings
     * @param  array<string, mixed>      $current
     * @param  string[]                  $warnings
     * @return array<string, mixed>
     */
    private function resolveReplacementSettings(string $docType, ?array $settings, array $current, array &$warnings): array
    {
        $identity = array_flip($this->identityKeys($docType));
        $resolved = array_diff_key($settings ?? $current, $identity);

        if ($this->isThemeLayoutDocType($docType)) {
            // Cornerstone derives the post type from layout_type on save, so the
            // post type decides it: a single stays a single.
            $storedType = $this->layoutTypeFor($docType);

            if (isset($resolved['layout_type']) && $resolved['layout_type'] !== $storedType) {
                $warnings[] = sprintf(
                    'Ignored layout_type "%s": this document stays a "%s" layout (changing it would change its post type).',
                    is_scalar($resolved['layout_type']) ? (string) $resolved['layout_type'] : gettype($resolved['layout_type']),
                    $storedType
                );
            }

            $resolved['layout_type'] = $storedType;
        } elseif ($this->isHeaderOrFooterDocType($docType) && ! array_key_exists('layout_type', $resolved) && isset($current['layout_type'])) {
            $resolved['layout_type'] = $current['layout_type'];
        }

        return $resolved;
    }

    /**
     * A document's elements exactly as stored (regions for layouts, the flat
     * map for components), or null when they cannot be read.
     *
     * @return array<mixed>|null
     */
    private function storedElements(\WP_Post $post, string $docType): ?array
    {
        $stored = Json::decodeStored($post->post_content);

        if (! is_array($stored)) {
            return null;
        }

        $elements = $this->isComponentDocType($docType) ? ($stored['elements'] ?? null) : ($stored['regions'] ?? null);

        return is_array($elements) && $elements !== [] ? $elements : null;
    }

    /**
     * The stored JSON shape of a document, built without Cornerstone.
     *
     * @param  array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function buildStoredShape(string $docType, string $title, string $slug, array $settings, ?array $elements, ?array $current): array
    {
        if ($this->isComponentDocType($docType)) {
            return [
                'elements' => $elements ?? self::INITIAL_COMPONENT_ELEMENTS,
                'settings' => array_merge(
                    [
                        'customCSS'           => '',
                        'customJS'            => '',
                        'general_post_title'  => $title,
                        'general_post_name'   => $slug,
                        'library_group'       => '',
                        'document_visibility' => '',
                    ],
                    $current ?? [],
                    $settings,
                    ['general_post_title' => $title, 'general_post_name' => $slug]
                ),
            ];
        }

        return [
            'settings' => $this->filterStoredSettings($docType, array_merge($this->knownStoredDefaults($docType), $current ?? [], $settings)),
            'regions'  => $elements ?? array_fill_keys($this->regionsFor($docType), []),
        ];
    }

    /**
     * @param  string[] $warnings
     * @return array{path: string, id: int, post_type: string, doc_type: string, title: string, warnings: string[]}
     */
    private function createdResult(string $path, int $id, string $postType, string $docType, array $warnings): array
    {
        return [
            'path'      => $path,
            'id'        => $id,
            'post_type' => $postType,
            'doc_type'  => $docType,
            'title'     => (string) get_post_field('post_title', $id),
            'warnings'  => $warnings,
        ];
    }

    /**
     * Cornerstone derives the post type from the document on save; refuse the
     * API save when that would change it (the caller then writes directly).
     *
     * @throws \RuntimeException
     */
    private function assertSaveKeepsPostType(object $doc, \WP_Post $post): void
    {
        $docType = method_exists($doc, 'getDocType') ? (string) $doc->getDocType() : '';
        $target = $this->postTypeForDocType($docType);

        if ($target !== $post->post_type) {
            throw new \RuntimeException(sprintf(
                'saving through Cornerstone would store this %s as "%s"',
                $post->post_type,
                $target ?? 'an unknown post type'
            ));
        }
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function requireDocumentPost(int $id): \WP_Post
    {
        $post = get_post($id);

        if (! $post instanceof \WP_Post) {
            throw new \InvalidArgumentException(sprintf('Post %d does not exist.', $id));
        }

        if (! in_array($post->post_type, array_merge(array_values(self::POST_TYPES), ['cs_layout']), true)) {
            throw new \InvalidArgumentException(sprintf(
                'Post %d is a "%s", not a Cornerstone header, footer, layout or component document.',
                $id,
                $post->post_type
            ));
        }

        return $post;
    }

    /**
     * Side effects of a document save, applied without Cornerstone's hooks.
     */
    private function fallbackSideEffects(\WP_Post $post): void
    {
        $metaIds = $this->postsWithCachedMeta();

        if ($post->post_type === 'cs_global_block') {
            delete_post_meta_by_key('_cs_component_map');
            delete_option('cs_component_cache');
            do_action('cs_purge_tmp');
            delete_post_meta_by_key('_cs_generated_styles');
            delete_post_meta_by_key('_cs_generated_tss');
        } else {
            $this->clearAssignments();
        }

        delete_option('x_cache_google_fonts_request');
        delete_post_meta($post->ID, '_cs_generated_tss');
        delete_post_meta($post->ID, '_cs_generated_styles');

        $this->touchLastSave($post->ID);

        clean_post_cache($post->ID);
        $this->clearResolverCache($post->ID);
        $this->clearMetaCache($metaIds);
        $this->forgetRegistry();
    }

    /**
     * Run a post write with Cornerstone's JSON-content guard hooks around it,
     * as Document::save() does (before WordPress 6.7 they stopped WordPress
     * rewriting target="_blank" links inside the JSON).
     *
     * @template T
     * @param  callable(): T $write
     * @return T
     */
    private function withJsonContentGuard(callable $write): mixed
    {
        $csGuard = has_action('cs_before_save_json_content') !== false;

        // WordPress 6.7 removed the link filters (the functions are deprecated
        // no-ops), so only call them directly on older versions.
        $wpGuard = ! $csGuard
            && version_compare((string) get_bloginfo('version'), '6.7', '<')
            && function_exists('wp_remove_targeted_link_rel_filters')
            && function_exists('wp_init_targeted_link_rel_filters');

        if ($csGuard) {
            do_action('cs_before_save_json_content');
        } elseif ($wpGuard) {
            wp_remove_targeted_link_rel_filters();
        }

        try {
            return $write();
        } finally {
            if ($csGuard) {
                do_action('cs_after_save_json_content');
            } elseif ($wpGuard) {
                wp_init_targeted_link_rel_filters();
            }
        }
    }

    /**
     * IDs of posts that have meta Cornerstone purges with $wpdb.
     *
     * @return int[]
     */
    private function postsWithCachedMeta(): array
    {
        global $wpdb;

        $placeholders = implode(', ', array_fill(0, count(self::GENERATED_META_KEYS), '%s'));

        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders})",
            ...self::GENERATED_META_KEYS
        ));

        return array_map('intval', is_array($ids) ? $ids : []);
    }

    /**
     * @param int[] $ids
     */
    private function clearMetaCache(array $ids): void
    {
        foreach (array_unique($ids) as $id) {
            wp_cache_delete($id, 'post_meta');
        }
    }

    private function clearResolverCache(int $id): void
    {
        $resolver = $this->service('Resolver');

        if ($resolver !== null && method_exists($resolver, 'clearDocumentCache')) {
            try {
                $resolver->clearDocumentCache($id);
            } catch (\Throwable) {
                // Nothing cached to clear.
            }
        }
    }

    /**
     * Read component exports straight from the component documents.
     *
     * @return array{components: array<string, array<string, mixed>>, doc_settings: array<string, mixed>, errors: string[]}
     */
    private function scanComponentDocuments(): array
    {
        if (! post_type_exists('cs_global_block')) {
            return ['components' => [], 'doc_settings' => [], 'errors' => []];
        }

        $posts = get_posts([
            'post_type'        => 'cs_global_block',
            'post_status'      => 'tco-data',
            'posts_per_page'   => 1000,
            'suppress_filters' => true,
            'orderby'          => 'ID',
            'order'            => 'ASC',
        ]);

        $components = [];
        $docSettings = [];
        $owners = [];
        $errors = [];

        foreach ($posts as $post) {
            $data = Json::decodeStored($post->post_content);

            if (! is_array($data) || ! is_array($data['elements'] ?? null)) {
                continue;
            }

            $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
            $docSettings['c' . $post->ID] = [
                (string) ($settings['customCSS'] ?? ''),
                (string) ($settings['customJS'] ?? ''),
                $settings['library_group'] ?? '',
                $settings['document_visibility'] ?? '',
            ];

            $scan = ComponentScanner::scan($data['elements']);

            foreach ($scan['duplicates'] as $cid => $count) {
                $errors[] = sprintf('Component _c_id "%s" is used by %d elements in document %d.', $cid, $count, $post->ID);
            }

            foreach ($scan['components'] as $cid => $info) {
                $owners[$cid][] = $post->ID;

                $component = [
                    'root' => $info['root'],
                    'doc'  => $post->ID,
                    'data' => [$info['root'] => $info['element']],
                ];

                if ($info['children']) {
                    $component['children'] = true;
                } elseif ($info['slots'] !== []) {
                    $component['slots'] = $info['slots'];
                }

                $components[$cid] = $component;
            }
        }

        foreach ($owners as $cid => $docIds) {
            if (count($docIds) > 1) {
                $errors[] = sprintf(
                    'Component _c_id "%s" is defined in more than one document: %s.',
                    $cid,
                    implode(', ', $docIds)
                );
            }
        }

        return ['components' => $components, 'doc_settings' => $docSettings, 'errors' => $errors];
    }
}
