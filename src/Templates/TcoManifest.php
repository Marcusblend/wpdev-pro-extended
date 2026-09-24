<?php

declare(strict_types=1);

namespace ProExtended\Templates;

/**
 * What Cornerstone's own importer, cs_import_tco(), would do with a .tco
 * archive, worked out before it runs.
 *
 * The importer (includes/functions/templates-api.php) walks the archive's
 * manifest.json task list in one pass and cannot describe without writing:
 *
 *   terms    imported first, by name: an existing term of that name in that
 *            taxonomy is reused and its slug and description overwritten
 *   options  global colors and fonts, merged into the site's by _id (an
 *            existing _id is replaced); a full-site export's Global CSS is
 *            carried here but not imported
 *   images   bundled files sideloaded into the Media Library, deduplicated by
 *            the `_cs_attachment_import` hash
 *   doc      each document or template, after every `_cs-tmpl:<hash>:cs-tmpl_`
 *            placeholder is replaced: images by "<new attachment id>:full",
 *            documents and components by their new post ids, terms by their
 *            new term ids. A "template" goes into the library; anything else
 *            is created as a document (header, footer, layout, component,
 *            page), deduplicated by its `_cs_import` signature
 *   menu     written by full-site exports, never read by cs_import_tco()
 *
 * The exporter turns every image the documents reference — an attachment
 * ("123:large") or a literal image URL — into a placeholder, so an imported
 * document points at this site's copy rather than the source site's URL.
 * An image the exporter could not bundle is marked "no-import", and the
 * importer then writes a reference with no id (":full"); those are reported.
 *
 * Nothing here calls WordPress: the site facts and the two checks come in as
 * arguments, so the whole plan is unit-tested.
 */
final class TcoManifest
{
    /** Task types cs_import_tco() acts on. */
    public const IMPORTED_TASKS = ['terms', 'options', 'images', 'doc'];

    /**
     * @param array{files?: string[], manifest?: array<mixed>|null, entries?: array<string, mixed>, svg?: array<string, string>} $archive TemplateGateway::readArchive()
     * @param array{element_types?: string[], document_types?: string[], taxonomies?: string[], can_upload?: bool, svg_allowed?: bool, color_ids?: string[], font_ids?: string[]} $site
     * @param callable(array<string, mixed>): ?string                          $codeReason TemplateGateway::codeReason()
     * @param callable(string): array{ok: bool, errors: string[]}              $svgCheck   SvgValidator::validate()
     * @return array<string, mixed>
     */
    public static function plan(array $archive, array $site, callable $codeReason, callable $svgCheck): array
    {
        $files = array_map('strval', (array) ($archive['files'] ?? []));
        $entries = (array) ($archive['entries'] ?? []);
        $svgMembers = (array) ($archive['svg'] ?? []);
        $manifest = $archive['manifest'] ?? null;

        $plan = [
            'version'   => null,
            'templates' => [],
            'documents' => [],
            'colors'    => ['count' => 0, 'replaces' => []],
            'fonts'     => ['count' => 0, 'replaces' => []],
            'images'    => ['bundled' => 0, 'missing' => []],
            'terms'     => [],
            'ignored'   => [],
            'blocking'  => [],
            'warnings'  => [],
        ];

        if (! is_array($manifest) || ! is_array($manifest['tasks'] ?? null)) {
            $plan['blocking'][] = ['what' => 'manifest.json', 'reason' => 'The archive has no manifest.json task list, so it is not a Cornerstone export Cornerstone can import.'];

            return $plan;
        }

        $plan['version'] = isset($manifest['version']) && is_scalar($manifest['version']) ? (int) $manifest['version'] : null;

        $elementTypes = (array) ($site['element_types'] ?? []);
        $documentTypes = (array) ($site['document_types'] ?? []);
        $taxonomies = (array) ($site['taxonomies'] ?? []);

        foreach ($manifest['tasks'] as $index => $task) {
            if (! is_array($task) || ! is_string($task[0] ?? null)) {
                $plan['ignored'][] = sprintf('Task %d is not a [type, data] pair.', (int) $index);
                continue;
            }

            $type = $task[0];
            $data = $task[1] ?? null;

            switch ($type) {
                case 'terms':
                    foreach ((array) $data as $term) {
                        if (! is_array($term)) {
                            continue;
                        }

                        $name = (string) ($term['name'] ?? '');
                        $taxonomy = (string) ($term['taxonomy'] ?? '');
                        $plan['terms'][] = ['name' => $name, 'taxonomy' => $taxonomy];

                        if (! in_array($taxonomy, $taxonomies, true)) {
                            $plan['blocking'][] = ['what' => sprintf('term "%s"', $name), 'reason' => sprintf('The taxonomy "%s" is not registered on this site; Cornerstone\'s importer stops on it.', $taxonomy)];
                        }
                    }
                    break;

                case 'options':
                    $options = is_array($data) ? $data : [];

                    foreach (['colors' => 'color_ids', 'fonts' => 'font_ids'] as $key => $siteKey) {
                        $items = is_array($options[$key] ?? null) ? $options[$key] : [];
                        $ids = array_values(array_filter(array_map(static fn (mixed $item): string => is_array($item) && is_scalar($item['_id'] ?? null) ? (string) $item['_id'] : '', $items)));
                        $plan[$key]['count'] += count($items);
                        $plan[$key]['replaces'] = array_values(array_unique(array_merge($plan[$key]['replaces'], array_intersect($ids, (array) ($site[$siteKey] ?? [])))));
                    }

                    if (isset($options['customCSS']) && $options['customCSS'] !== '') {
                        $plan['ignored'][] = 'Global CSS from a full-site export: Cornerstone\'s importer does not import it (set_global_css writes it).';
                    }
                    break;

                case 'images':
                    foreach ((array) $data as $hash => $image) {
                        $kind = is_array($image) ? (string) ($image[0] ?? '') : '';
                        $name = is_array($image) ? (string) ($image[1] ?? '') : '';
                        $member = 'img-' . $hash . '-' . $name;

                        if (! in_array($kind, ['id', 'uri'], true) || ! in_array($member, $files, true)) {
                            $plan['images']['missing'][] = $name;
                            continue;
                        }

                        $plan['images']['bundled']++;

                        if (str_ends_with(strtolower($name), '.svg')) {
                            if (empty($site['svg_allowed'])) {
                                $plan['blocking'][] = ['what' => $name, 'reason' => 'The archive carries an SVG and SVG uploads are turned off on this site (pe_allow_svg_uploads).'];
                                continue;
                            }

                            $check = $svgCheck((string) ($svgMembers[$member] ?? ''));

                            if (empty($check['ok'])) {
                                $plan['blocking'][] = ['what' => $name, 'reason' => 'SVG refused: ' . implode(' ', (array) ($check['errors'] ?? []))];
                            }
                        }
                    }
                    break;

                case 'doc':
                    self::planDoc($plan, is_array($data) ? $data : [], $entries, $elementTypes, $documentTypes, $codeReason);
                    break;

                case 'menu':
                    $plan['ignored'][] = sprintf('Menu "%s": Cornerstone\'s importer does not import menus (create_menu builds them).', is_array($data) ? (string) ($data['name'] ?? '') : '');
                    break;

                default:
                    $plan['ignored'][] = sprintf('A "%s" task, which Cornerstone\'s importer does not act on.', $type);
            }
        }

        if ($plan['images']['bundled'] > 0 && empty($site['can_upload'])) {
            $plan['blocking'][] = ['what' => 'images', 'reason' => sprintf('The archive carries %d image(s), which are added to the Media Library; that needs the upload_files capability.', $plan['images']['bundled'])];
        }

        if ($plan['images']['missing'] !== []) {
            $plan['warnings'][] = sprintf('%d image(s) were not bundled by the exporter; every reference to them imports as an empty image (":full"). Re-point those settings after the import.', count($plan['images']['missing']));
        }

        foreach (['colors', 'fonts'] as $key) {
            if ($plan[$key]['replaces'] !== []) {
                $plan['warnings'][] = sprintf('The archive\'s %s replace this site\'s items with the same _id: %s. They are backed up first, so restore_settings can undo it.', $key, implode(', ', $plan[$key]['replaces']));
            }
        }

        if ($plan['documents'] !== []) {
            $plan['warnings'][] = 'Documents are created the way the builder\'s import creates them: live, with the settings they were exported with (a header or layout keeps its assignments).';
        }

        return $plan;
    }

    /**
     * @param array<string, mixed>            $plan
     * @param array<string, mixed>            $task    {type, strategy, key, file}
     * @param array<string, mixed>            $entries
     * @param string[]                        $elementTypes
     * @param string[]                        $documentTypes
     * @param callable(array<string, mixed>): ?string $codeReason
     */
    private static function planDoc(array &$plan, array $task, array $entries, array $elementTypes, array $documentTypes, callable $codeReason): void
    {
        $file = (string) ($task['file'] ?? '');
        $data = $entries[$file] ?? null;

        if (! is_array($data)) {
            $plan['blocking'][] = ['what' => $file === '' ? 'a document task' : $file, 'reason' => 'The manifest names a document the archive does not hold (or it is not JSON).'];

            return;
        }

        $title = is_string($data['title'] ?? null) && $data['title'] !== '' ? $data['title'] : 'Untitled';
        $type = (string) ($data['type'] ?? '');
        $subType = (string) ($data['subType'] ?? '');
        $identifier = TemplateIdentifier::format($type, $subType);
        $strategy = (string) ($task['strategy'] ?? 'original');
        $what = sprintf('"%s" (%s)', $title, $identifier);

        foreach (TemplateIdentifier::problems($type, $subType, $elementTypes, $documentTypes) as $problem) {
            $plan['blocking'][] = ['what' => $what, 'reason' => $problem];
        }

        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];

        // An archive is someone else's file. Cornerstone's importer does not
        // check for code the importing user may not write, so the same rule
        // as create_template runs over every entry first.
        $codeProblem = $codeReason($meta);

        if ($codeProblem !== null) {
            $plan['blocking'][] = ['what' => $what, 'reason' => $codeProblem];
        }

        if (($task['type'] ?? null) === 'template') {
            $plan['templates'][] = [
                'title'      => $title,
                'identifier' => $identifier,
                'kind'       => TemplateIdentifier::kind($type, $subType),
                'replaces'   => $strategy === 'replace',
            ];

            return;
        }

        $settings = is_array($meta['settings'] ?? null) ? $meta['settings'] : [];

        $plan['documents'][] = [
            'title'       => $title,
            'doc_type'    => $subType,
            'as'          => (string) ($task['type'] ?? 'doc'),
            'signature'   => is_string($data['sig'] ?? null) ? $data['sig'] : null,
            'replaces'    => $strategy === 'replace',
            'assignments' => is_array($settings['assignments'] ?? null) ? count($settings['assignments']) : 0,
        ];
    }
}
