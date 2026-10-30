<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Image;

/**
 * Reads the intrinsic size of SVG and PNG branding assets.
 *
 * Shared by the upload validation (app icon aspect ratio) and the settings
 * endpoint, which hands logo sizes to the frontend so the header can reserve
 * the logo's space before the file has loaded.
 */
final class BrandingImageDimensions {

  /**
   * Returns the intrinsic size of SVG or PNG data.
   *
   * SVG sizes come from absolute width and height attributes, otherwise from
   * the viewBox. Percentage or relative sizing is never guessed.
   *
   * @param string $data
   *   The raw file contents.
   * @param string $extension
   *   The lower-case file extension, 'svg' or 'png'.
   *
   * @return array{width: float, height: float}|null
   *   The size, or NULL when it is not authoritative.
   */
  public static function fromData(string $data, string $extension): ?array {
    if ($extension === 'png') {
      $imageInfo = @getimagesizefromstring($data);
      if (
        !is_array($imageInfo)
        || ($imageInfo[2] ?? NULL) !== IMAGETYPE_PNG
        || empty($imageInfo[0])
        || empty($imageInfo[1])
      ) {
        return NULL;
      }
      return ['width' => (float) $imageInfo[0], 'height' => (float) $imageInfo[1]];
    }

    if ($extension !== 'svg') {
      return NULL;
    }

    $document = self::loadSvgDocument($data);
    if ($document === NULL) {
      return NULL;
    }
    $svg = $document->documentElement;
    if (!$svg instanceof \DOMElement) {
      return NULL;
    }
    $width = self::parseSvgDimension($svg->getAttribute('width'));
    $height = self::parseSvgDimension($svg->getAttribute('height'));
    if ($width !== NULL && $height !== NULL) {
      return ['width' => $width, 'height' => $height];
    }

    $viewBox = trim(
      $svg->getAttribute('viewBox') ?: $svg->getAttribute('viewbox'),
    );
    if ($viewBox === '') {
      return NULL;
    }
    $parts = preg_split('/[\s,]+/', $viewBox);
    if (
      !is_array($parts)
      || count($parts) !== 4
      || !is_numeric($parts[2])
      || !is_numeric($parts[3])
    ) {
      return NULL;
    }
    $viewBoxWidth = (float) $parts[2];
    $viewBoxHeight = (float) $parts[3];
    if (
      $viewBoxWidth <= 0
      || $viewBoxHeight <= 0
      || !is_finite($viewBoxWidth)
      || !is_finite($viewBoxHeight)
    ) {
      return NULL;
    }

    return ['width' => $viewBoxWidth, 'height' => $viewBoxHeight];
  }

  /**
   * Loads SVG data without network access and requires an SVG root element.
   */
  public static function loadSvgDocument(string $data): ?\DOMDocument {
    $document = new \DOMDocument();
    $previous = libxml_use_internal_errors(TRUE);
    try {
      $loaded = $document->loadXML($data, LIBXML_NONET);
    }
    catch (\Throwable) {
      $loaded = FALSE;
    }
    finally {
      libxml_clear_errors();
      libxml_use_internal_errors($previous);
    }
    if (
      !$loaded
      || $document->documentElement === NULL
      || strtolower($document->documentElement->localName) !== 'svg'
      || $document->documentElement->namespaceURI !== 'http://www.w3.org/2000/svg'
    ) {
      return NULL;
    }

    return $document;
  }

  /**
   * Parses an absolute SVG dimension without guessing percentage sizing.
   */
  public static function parseSvgDimension(string $value): ?float {
    if (
      preg_match(
        '/^\s*(\d+(?:\.\d+)?)(?:px)?\s*$/i',
        $value,
        $matches,
      ) !== 1
    ) {
      return NULL;
    }
    $dimension = (float) $matches[1];
    return $dimension > 0 && is_finite($dimension) ? $dimension : NULL;
  }

}
