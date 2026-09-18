<?php

declare(strict_types=1);

namespace ProExtended\Cornerstone;

/**
 * Renders element data to HTML without saving anything.
 *
 * The slow part of building through an API is not writing the elements, it is
 * finding out what they render to: a looper with no results, a condition that
 * hides everything, a token that resolves to nothing, a mega menu that comes
 * out empty. All of those save without complaint. Rendering a subtree against a
 * chosen post answers the question in one call instead of a save and a look.
 *
 * Three things have to happen for the output to match the front end: the
 * elements go through Cornerstone's migrations, their defaults are applied (an
 * element rendered without them emits broken markup — a headline's tag comes
 * out as "<"), and the result is run through Dynamic Content. The post context
 * is set up and put back afterwards, because loopers and tokens read it.
 */
final class Renderer
{
    /**
     * Render elements as the front end would.
     *
     * @param  array<int, mixed> $elements
     * @return array{html: string, bytes: int, expanded: bool, context_post_id: int|null, warnings: string[]}
     */
    public function render(array $elements, ?int $contextPostId = null, bool $expandTokens = true): array
    {
        if (! function_exists('cornerstone')) {
            throw new \RuntimeException('Cornerstone is not available on this site.');
        }

        $warnings = [];
        $previousPost = $GLOBALS['post'] ?? null;
        $setUp = false;

        if ($contextPostId !== null) {
            $post = get_post($contextPostId);

            if (! $post instanceof \WP_Post) {
                throw new \InvalidArgumentException(sprintf('Post %d does not exist, so it cannot be the context.', $contextPostId));
            }

            $GLOBALS['post'] = $post;
            setup_postdata($post);
            $setUp = true;
        }

        try {
            $service = cornerstone('Elements');
            $prepared = $this->prepare($service, $elements, $warnings);

            // Cornerstone's renderer expects the bookkeeping keys a saved
            // document already carries and reaches for them without checking,
            // so rendering a loose subtree raises notices for every one it
            // misses. The output is right; the notices are not ours to emit
            // into a tool response or the site's log.
            set_error_handler(static fn (): bool => true, E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE | E_DEPRECATED);

            try {
                $html = (string) $service->renderElements($prepared);
            } finally {
                restore_error_handler();
            }

            if ($expandTokens) {
                $html = $this->expand($html, $warnings);
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException('The elements could not be rendered: ' . $e->getMessage(), 0, $e);
        } finally {
            if ($setUp) {
                wp_reset_postdata();
            }

            $GLOBALS['post'] = $previousPost;
        }

        return [
            'html'            => $html,
            'bytes'           => strlen($html),
            'expanded'        => $expandTokens,
            'context_post_id' => $contextPostId,
            'warnings'        => $warnings,
        ];
    }

    /**
     * Migrate and fill in defaults, as a stored document would already have.
     *
     * @param  array<int, mixed> $elements
     * @param  string[]          $warnings
     * @return array<int, mixed>
     */
    private function prepare(object $service, array $elements, array &$warnings): array
    {
        $prepared = $elements;

        if (method_exists($service, 'migrations')) {
            try {
                $prepared = $service->migrations()->migrate(array_values($prepared));
            } catch (\Throwable) {
                $warnings[] = 'The elements could not be migrated, so they were rendered as given.';
                $prepared = array_values($elements);
            }
        }

        $counter = 0;

        foreach ($prepared as $index => $element) {
            if (! is_array($element) || ! is_string($element['_type'] ?? null)) {
                continue;
            }

            $prepared[$index] = $this->fill($service, $this->identify($element, $counter), $warnings);
        }

        return $prepared;
    }

    /**
     * Apply an element's defaults, and its children's.
     *
     * Every level needs them, not just the top: an element rendered without
     * its defaults emits broken markup — a headline's tag comes out as "<" —
     * and a nested one is no different.
     *
     * @param  array<string, mixed> $element
     * @param  string[]             $warnings
     * @return array<string, mixed>
     */
    private function fill(object $service, array $element, array &$warnings): array
    {
        $children = isset($element['_modules']) && is_array($element['_modules']) ? $element['_modules'] : null;

        if ($children !== null) {
            unset($element['_modules']);
        }

        $type = is_string($element['_type'] ?? null) ? $element['_type'] : '';

        if ($type !== '') {
            try {
                $definition = $service->get_element($type);
            } catch (\Throwable) {
                $definition = null;
            }

            if (is_object($definition) && method_exists($definition, 'apply_defaults')) {
                $element = $definition->apply_defaults($element);
            } else {
                $warnings[] = sprintf('"%s" has no definition on this site, so it rendered without its defaults.', $type);
            }
        }

        if ($children !== null) {
            foreach ($children as $key => $child) {
                if (is_array($child)) {
                    $children[$key] = $this->fill($service, $child, $warnings);
                }
            }

            $element['_modules'] = array_values($children);
        }

        return $element;
    }

    /**
     * Give every element an id, which the renderer and the style generator
     * both read. A saved document has them; a subtree passed in may not.
     *
     * @param  array<string, mixed> $element
     * @return array<string, mixed>
     */
    private function identify(array $element, int &$counter): array
    {
        if (! isset($element['_id']) || ! is_string($element['_id']) || $element['_id'] === '') {
            $element['_id'] = 'pe' . $counter;
        }

        $counter++;

        if (isset($element['_modules']) && is_array($element['_modules'])) {
            foreach ($element['_modules'] as $key => $child) {
                if (is_array($child)) {
                    $element['_modules'][$key] = $this->identify($child, $counter);
                }
            }
        }

        return $element;
    }

    /**
     * @param string[] $warnings
     */
    private function expand(string $html, array &$warnings): string
    {
        try {
            $service = cornerstone('DynamicContent');

            if (! is_object($service) || ! method_exists($service, 'run')) {
                $warnings[] = 'Dynamic Content is not available, so tokens were left unexpanded.';

                return $html;
            }

            $expanded = $service->run($html);
        } catch (\Throwable $e) {
            $warnings[] = 'Dynamic Content failed, so tokens were left unexpanded: ' . $e->getMessage();

            return $html;
        }

        return is_string($expanded) ? $expanded : $html;
    }
}
