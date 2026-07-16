<?php

namespace App\Support;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Sanitizes user-submitted HTML from the rich-text editor before it goes
 * into the database. Only a small whitelist of tags + attributes is kept;
 * everything else (script tags, inline event handlers, javascript: URLs,
 * etc.) is stripped.
 */
class HtmlSanitizer
{
    public static function clean(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        static $purifier = null;

        if ($purifier === null) {
            $config = HTMLPurifier_Config::createDefault();
            // Quill output tags + a couple of extras the editor emits.
            // class= is kept on block elements so Quill's alignment classes
            // (ql-align-center/right/justify) survive — see Attr.AllowedClasses
            // below for the whitelist.
            $config->set(
                'HTML.Allowed',
                'p[class],br,strong,em,u,s,h1[class],h2[class],h3[class],ul,ol,li[class],a[href|target|rel],img[src|alt|width|height],blockquote[class],code,pre,hr,'
                .'table,thead,tbody,tfoot,tr,th[colspan|rowspan],td[colspan|rowspan],colgroup,col'
            );
            $config->set('Attr.AllowedClasses', [
                'ql-align-center',
                'ql-align-right',
                'ql-align-justify',
            ]);
            $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'data' => true]);
            $config->set('HTML.TargetBlank', true);
            $config->set('AutoFormat.RemoveEmpty', true);
            // Don't cache to disk — keeps the local-dev experience portable.
            $config->set('Cache.DefinitionImpl', null);

            $purifier = new HTMLPurifier($config);
        }

        return $purifier->purify($html);
    }
}
