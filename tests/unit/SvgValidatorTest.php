<?php

declare(strict_types=1);

use ProExtended\Media\SvgValidator;

T::group('SvgValidator');

$benign = '<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 24 24" width="24" height="24" role="img" aria-label="Logo">
  <title>Logo</title>
  <defs>
    <linearGradient id="g1" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#000"/><stop offset="1" stop-color="#fff"/></linearGradient>
    <symbol id="dot" viewBox="0 0 2 2"><circle cx="1" cy="1" r="1"/></symbol>
  </defs>
  <g fill="url(#g1)" transform="translate(1 1)"><path d="M0 0h10v10z"/><rect x="2" y="2" width="4" height="4" rx="1"/></g>
  <use xlink:href="#dot" x="5" y="5"/>
  <use href="#dot" x="8" y="8"/>
</svg>';

$ok = SvgValidator::validate($benign);
T::same([], $ok['errors'], 'accepts a benign SVG');
T::ok($ok['ok'] && is_string($ok['svg']) && str_contains($ok['svg'], '<svg'), 'returns the re-serialized document');

$bad = [
    'script element'  => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
    'onload'          => '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect width="1" height="1"/></svg>',
    'style element'   => '<svg xmlns="http://www.w3.org/2000/svg"><style>rect{fill:red}</style></svg>',
    'style attribute' => '<svg xmlns="http://www.w3.org/2000/svg"><rect style="fill:red" width="1" height="1"/></svg>',
    'anchor'          => '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><a xlink:href="https://example.com"><rect width="1" height="1"/></a></svg>',
    'animate'         => '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"><animate attributeName="x" to="5"/></rect></svg>',
    'set'             => '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"><set attributeName="x" to="5"/></rect></svg>',
    'foreignObject'   => '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><div xmlns="http://www.w3.org/1999/xhtml">x</div></foreignObject></svg>',
    'external use'    => '<svg xmlns="http://www.w3.org/2000/svg"><use href="https://evil.example/sprite.svg#icon"/></svg>',
    'xxe'             => '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg"><title>&xxe;</title></svg>',
    'doctype only'    => '<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd"><svg xmlns="http://www.w3.org/2000/svg"/>',
    'xml:base'        => '<svg xmlns="http://www.w3.org/2000/svg" xml:base="https://evil.example/"><rect width="1" height="1"/></svg>',
    'javascript href' => '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><use xlink:href="javascript:alert(1)"/></svg>',
    'data href'       => '<svg xmlns="http://www.w3.org/2000/svg"><use href="data:image/svg+xml;base64,PHN2Zy8+"/></svg>',
    'xinclude'        => '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xi="http://www.w3.org/2001/XInclude"><xi:include href="file:///etc/passwd"/></svg>',
    'pi'              => '<?xml version="1.0"?><?xml-stylesheet href="evil.css"?><svg xmlns="http://www.w3.org/2000/svg"/>',
    'external url()'  => '<svg xmlns="http://www.w3.org/2000/svg"><rect fill="url(https://evil.example/x.svg#p)" width="1" height="1"/></svg>',
    'image element'   => '<svg xmlns="http://www.w3.org/2000/svg"><image href="https://evil.example/x.png"/></svg>',
    'svgz'            => "\x1f\x8b\x08\x00compressed",
    'not svg root'    => '<html xmlns="http://www.w3.org/1999/xhtml"/>',
    'no namespace'    => '<svg><rect width="1" height="1"/></svg>',
    'cdata'           => '<svg xmlns="http://www.w3.org/2000/svg"><title><![CDATA[x]]></title></svg>',
    'foreign attr ns' => '<svg xmlns="http://www.w3.org/2000/svg" xmlns:ev="http://www.w3.org/2001/xml-events"><rect ev:event="click" width="1" height="1"/></svg>',
    'href on gradient' => '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><linearGradient id="a" xlink:href="#b"/></svg>',
    'malformed'       => '<svg xmlns="http://www.w3.org/2000/svg"><rect></svg>',
];

foreach ($bad as $label => $svg) {
    $result = SvgValidator::validate($svg);
    T::ok(! $result['ok'] && $result['errors'] !== [], 'refuses ' . $label, implode(' ', $result['errors']));
}
