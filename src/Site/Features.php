<?php

declare(strict_types=1);

namespace ProExtended\Site;

/**
 * Cornerstone feature switches and plugin integrations that change how a
 * site renders or what its elements can do.
 *
 * Everything is read with get_option() (never cs_get_option(), which creates
 * a missing option when it reads it) and nothing here returns a secret.
 */
final class Features
{
    /**
     * Whether pages store rendered HTML (true) or [cs_content] shortcodes.
     * Cornerstone defaults to HTML, but switched sites upgraded from older
     * versions to shortcodes.
     */
    public static function buildsHtml(): bool
    {
        return (bool) get_option('cs_document_build_as_html', true);
    }

    public static function csvEnabled(): bool
    {
        return (bool) get_option('cs_csv_enabled', true);
    }

    /**
     * Whether the External API can run: the switch is on and PHP has cURL
     * (Cornerstone loads the integration only then).
     */
    public static function externalApiEnabled(): bool
    {
        return function_exists('curl_init') && (bool) get_option('cs_api_extension_enabled', false);
    }

    public static function woocommerceActive(): bool
    {
        return class_exists('WooCommerce');
    }

    /**
     * The switches ElementLint checks loopers against.
     *
     * @return array{csv: bool, external_api: bool, woocommerce: bool}
     */
    public static function lintSwitches(): array
    {
        return [
            'csv'          => self::csvEnabled(),
            'external_api' => self::externalApiEnabled(),
            'woocommerce'  => self::woocommerceActive(),
        ];
    }
}
