<?php

declare(strict_types=1);

namespace ProExtended\Media;

/**
 * Accepts an SVG only when every node and attribute is on an allowlist.
 *
 * Nothing is stripped: an SVG with anything outside the allowlist is refused,
 * so what is stored is exactly what was reviewed. Accepted files are stored in
 * their re-serialized form.
 *
 * Pure PHP (ext-dom), so it is unit-tested without a site.
 */
final class SvgValidator
{
    public const MAX_BYTES = 5242880;

    private const SVG_NS   = 'http://www.w3.org/2000/svg';
    private const XLINK_NS = 'http://www.w3.org/1999/xlink';
    private const XML_NS   = 'http://www.w3.org/XML/1998/namespace';

    private const ELEMENTS = [
        'svg', 'g', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
        'title', 'desc', 'defs', 'linearGradient', 'radialGradient', 'stop', 'clipPath',
        'mask', 'symbol', 'use',
    ];

    private const TEXT_ELEMENTS = ['title', 'desc'];

    private const PRESENTATION_ATTRIBUTES = [
        'alignment-baseline', 'baseline-shift', 'clip', 'clip-path', 'clip-rule', 'color',
        'color-interpolation', 'color-interpolation-filters', 'color-profile', 'color-rendering',
        'direction', 'display', 'dominant-baseline', 'enable-background', 'fill', 'fill-opacity',
        'fill-rule', 'filter', 'flood-color', 'flood-opacity', 'font-family', 'font-size',
        'font-size-adjust', 'font-stretch', 'font-style', 'font-variant', 'font-weight',
        'glyph-orientation-horizontal', 'glyph-orientation-vertical', 'image-rendering', 'isolation',
        'kerning', 'letter-spacing', 'lighting-color', 'marker-end', 'marker-mid', 'marker-start',
        'mask', 'mix-blend-mode', 'opacity', 'overflow', 'paint-order', 'pointer-events',
        'shape-rendering', 'stop-color', 'stop-opacity', 'stroke', 'stroke-dasharray',
        'stroke-dashoffset', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit',
        'stroke-opacity', 'stroke-width', 'text-anchor', 'text-decoration', 'text-rendering',
        'transform', 'transform-origin', 'unicode-bidi', 'vector-effect', 'visibility',
        'word-spacing', 'writing-mode',
    ];

    private const GEOMETRY_ATTRIBUTES = [
        'x', 'y', 'width', 'height', 'cx', 'cy', 'r', 'rx', 'ry', 'x1', 'y1', 'x2', 'y2',
        'points', 'd', 'pathLength', 'viewBox', 'preserveAspectRatio', 'gradientUnits',
        'gradientTransform', 'spreadMethod', 'fx', 'fy', 'fr', 'offset', 'clipPathUnits',
        'maskUnits', 'maskContentUnits', 'refX', 'refY', 'version', 'baseProfile',
    ];

    private const GENERAL_ATTRIBUTES = ['id', 'class', 'role', 'lang', 'focusable'];

    /**
     * @return array{ok: bool, svg: string|null, errors: string[]}
     */
    public static function validate(string $bytes): array
    {
        if (str_starts_with($bytes, "\x1f\x8b")) {
            return self::fail('Compressed SVG (svgz) is not accepted.');
        }

        if (strlen($bytes) > self::MAX_BYTES) {
            return self::fail('The SVG is larger than 5 MB.');
        }

        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $bytes)) {
            return self::fail('DOCTYPE and entity declarations are not allowed in SVG.');
        }

        if (preg_match('/<\?(?!xml[\s?])/i', $bytes)) {
            return self::fail('Processing instructions are not allowed in SVG.');
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $loaded = $dom->loadXML($bytes, LIBXML_NONET);
        $parseErrors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || $dom->documentElement === null) {
            $first = $parseErrors[0] ?? null;

            return self::fail('The SVG is not well-formed XML' . ($first ? ': ' . trim($first->message) : '.'));
        }

        if ($dom->doctype !== null) {
            return self::fail('DOCTYPE declarations are not allowed in SVG.');
        }

        $root = $dom->documentElement;

        if ($root->localName !== 'svg' || $root->namespaceURI !== self::SVG_NS) {
            return self::fail('The root element must be <svg> in the SVG namespace.');
        }

        $errors = [];
        $xpath = new \DOMXPath($dom);
        self::checkElement($root, $xpath, $errors);
        self::checkNode($root, $xpath, $errors);

        if ($errors !== []) {
            return ['ok' => false, 'svg' => null, 'errors' => array_values(array_unique($errors))];
        }

        $svg = $dom->saveXML();

        return ['ok' => true, 'svg' => is_string($svg) ? $svg : null, 'errors' => []];
    }

    /**
     * @param string[] $errors
     */
    private static function checkNode(\DOMNode $node, \DOMXPath $xpath, array &$errors): void
    {
        foreach ($node->childNodes as $child) {
            switch ($child->nodeType) {
                case XML_ELEMENT_NODE:
                    /** @var \DOMElement $child */
                    self::checkElement($child, $xpath, $errors);
                    self::checkNode($child, $xpath, $errors);
                    break;

                case XML_TEXT_NODE:
                    if (trim((string) $child->nodeValue) !== '' && ! in_array($node->localName, self::TEXT_ELEMENTS, true)) {
                        $errors[] = 'Text content is only allowed inside <title> and <desc>.';
                    }
                    break;

                case XML_COMMENT_NODE:
                    break;

                case XML_CDATA_SECTION_NODE:
                    $errors[] = 'CDATA sections are not allowed.';
                    break;

                case XML_PI_NODE:
                    $errors[] = 'Processing instructions are not allowed.';
                    break;

                case XML_ENTITY_REF_NODE:
                    $errors[] = 'Entity references are not allowed.';
                    break;

                default:
                    $errors[] = 'Unsupported XML node type.';
            }
        }
    }

    /**
     * @param string[] $errors
     */
    private static function checkElement(\DOMElement $element, \DOMXPath $xpath, array &$errors): void
    {
        $name = $element->localName;

        if ($element->namespaceURI !== self::SVG_NS) {
            $errors[] = sprintf('Element <%s> is not in the SVG namespace.', $element->nodeName);
            return;
        }

        if (! in_array($name, self::ELEMENTS, true)) {
            $errors[] = sprintf('Element <%s> is not allowed.', $name);
            return;
        }

        foreach ($xpath->query('namespace::*', $element) ?: [] as $namespace) {
            $uri = $namespace->nodeValue;

            if (! in_array($uri, [self::SVG_NS, self::XLINK_NS, self::XML_NS], true)) {
                $errors[] = sprintf('Namespace "%s" is not allowed.', (string) $uri);
            }
        }

        foreach ($element->attributes as $attribute) {
            self::checkAttribute($element, $attribute, $errors);
        }
    }

    /**
     * @param string[] $errors
     */
    private static function checkAttribute(\DOMElement $element, \DOMAttr $attribute, array &$errors): void
    {
        $local = (string) ($attribute->localName ?? $attribute->nodeName);
        $namespace = $attribute->namespaceURI;
        $value = (string) $attribute->value;
        $label = $attribute->nodeName;

        if (self::hasUnsafeScheme($value)) {
            $errors[] = sprintf('Attribute %s on <%s> contains a javascript:, vbscript: or data: reference.', $label, $element->localName);
            return;
        }

        $isHref = $local === 'href' && ($namespace === null || $namespace === self::XLINK_NS);

        if ($isHref) {
            if ($element->localName !== 'use') {
                $errors[] = sprintf('%s is only allowed on <use>.', $label);
            } elseif (! preg_match('/^#[A-Za-z_][\w.\-:]*$/', trim($value))) {
                $errors[] = sprintf('<use> may only reference an element in the same file (%s="#id").', $label);
            }

            return;
        }

        if ($namespace === self::XML_NS) {
            if ($local === 'space' || $local === 'lang') {
                return;
            }

            $errors[] = sprintf('Attribute %s is not allowed.', $label);
            return;
        }

        if ($namespace !== null) {
            $errors[] = sprintf('Attribute %s is not allowed.', $label);
            return;
        }

        if (str_starts_with(strtolower($local), 'on')) {
            $errors[] = sprintf('Event handler attribute %s is not allowed.', $label);
            return;
        }

        if ($local === 'style') {
            $errors[] = 'The style attribute is not allowed; use presentation attributes.';
            return;
        }

        $allowed = in_array($local, self::PRESENTATION_ATTRIBUTES, true)
            || in_array($local, self::GEOMETRY_ATTRIBUTES, true)
            || in_array($local, self::GENERAL_ATTRIBUTES, true)
            || preg_match('/^aria-[a-z]+$/', $local) === 1;

        if (! $allowed) {
            $errors[] = sprintf('Attribute %s on <%s> is not allowed.', $label, $element->localName);
            return;
        }

        if (stripos($value, 'url(') !== false && ! preg_match('/^\s*url\(\s*[\'"]?#[A-Za-z_][\w.\-:]*[\'"]?\s*\)\s*$/', $value)) {
            $errors[] = sprintf('Attribute %s on <%s> may only use url(#id) references.', $label, $element->localName);
        }
    }

    private static function hasUnsafeScheme(string $value): bool
    {
        $normalized = strtolower(preg_replace('/[\s\x00-\x1f]+/', '', $value) ?? $value);

        return str_contains($normalized, 'javascript:')
            || str_contains($normalized, 'vbscript:')
            || str_contains($normalized, 'data:');
    }

    /**
     * @return array{ok: false, svg: null, errors: string[]}
     */
    private static function fail(string $message): array
    {
        return ['ok' => false, 'svg' => null, 'errors' => [$message]];
    }
}
