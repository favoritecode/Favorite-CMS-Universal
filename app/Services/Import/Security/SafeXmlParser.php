<?php

declare(strict_types=1);

namespace FavoriteCMS\Services\Import\Security;

use DOMDocument;
use DOMXPath;
use InvalidArgumentException;

class SafeXmlParser
{
    /**
     * Safely parse an XML string into a DOMDocument, strictly mitigating XXE, entity expansion, and external entity loads.
     *
     * @param string $xmlContent
     * @return DOMDocument
     * @throws InvalidArgumentException
     */
    public static function parse(string $xmlContent): DOMDocument
    {
        $trimmed = trim($xmlContent);
        if ($trimmed === '') {
            throw new InvalidArgumentException('XML content is empty.');
        }

        // XXE Mitigation 1: Reject external DOCTYPE / ENTITY declarations
        if (preg_match('/<!ENTITY|<!DOCTYPE[^>]*SYSTEM/i', $trimmed)) {
            throw new InvalidArgumentException('Security violation: XML contains disallowed DOCTYPE or ENTITY definitions.');
        }

        // XXE Mitigation 2: Reject parameter entities or DTD inclusions
        if (preg_match('/%[a-zA-Z0-9_-]+;/i', $trimmed)) {
            throw new InvalidArgumentException('Security violation: XML contains disallowed parameter entity references.');
        }

        if (PHP_VERSION_ID < 80000 && function_exists('libxml_disable_entity_loader')) {
            /** @noinspection PhpDeprecationInspection */
            libxml_disable_entity_loader(true);
        }

        $prevInternalErrors = libxml_use_internal_errors(true);

        $doc = new DOMDocument();
        // LIBXML_NONET prevents network access; LIBXML_NOWARNING | LIBXML_NOERROR suppress parse warnings
        $options = LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR;

        $loaded = $doc->loadXML($trimmed, $options);
        libxml_use_internal_errors($prevInternalErrors);

        if (!$loaded || !$doc->documentElement) {
            throw new InvalidArgumentException('Invalid XML structure: Document could not be parsed.');
        }

        return $doc;
    }

    /**
     * Create a DOMXPath instance registered with common CMS export XML namespaces.
     */
    public static function createXPath(DOMDocument $doc): DOMXPath
    {
        $xpath = new DOMXPath($doc);
        
        // Atom & Blogger namespaces
        $xpath->registerNamespace('atom', 'http://www.w3.org/2005/Atom');
        $xpath->registerNamespace('app', 'http://purl.org/atom/app#');
        $xpath->registerNamespace('app2007', 'http://www.w3.org/2007/app');
        $xpath->registerNamespace('thr', 'http://purl.org/syndication/thread/1.0');
        $xpath->registerNamespace('gd', 'http://schemas.google.com/g/2005');

        // WordPress WXR & RSS namespaces
        $xpath->registerNamespace('content', 'http://purl.org/rss/1.0/modules/content/');
        $xpath->registerNamespace('wfw', 'http://wellformedweb.org/CommentAPI/');
        $xpath->registerNamespace('dc', 'http://purl.org/dc/elements/1.1/');
        $xpath->registerNamespace('wp', 'http://wordpress.org/export/1.2/');
        $xpath->registerNamespace('wp11', 'http://wordpress.org/export/1.1/');
        $xpath->registerNamespace('wp10', 'http://wordpress.org/export/1.0/');
        $xpath->registerNamespace('excerpt', 'http://wordpress.org/export/1.2/excerpt/');

        // Generic Media RSS
        $xpath->registerNamespace('media', 'http://search.yahoo.com/mrss/');

        return $xpath;
    }
}
