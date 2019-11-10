<?php
/**
 * Responsive Images plugin for Craft CMS 3.x
 *
 * @copyright Copyright (c) 2018 Pieter Beulque
 */

namespace gentsagency\responsiveimages\twigextensions;

use gentsagency\responsiveimages\ResponsiveImages;
use gentsagency\responsiveimages\models\ResponsiveImage;

use Craft;

/**
 * Twig can be extended in many ways; you can add extra tags, filters, tests, operators,
 * global variables, and functions. You can even extend the parser itself with
 * node visitors.
 *
 * http://twig.sensiolabs.org/doc/advanced.html
 *
 * @author    Pieter Beulque
 * @package   ResponsiveImages
 * @since     0.0.1
 */
class ResponsiveImagesTwigExtension extends \Twig_Extension
{
    /**
     * Imgix URL builders instances per volume
     *
     * @var \Imgix\URLBuilder[]
     */
    private $imgixBuilders = array();

    /**
     * Returns the name of the extension.
     *
     * @return string The extension name
     */
    public function getName() : string
    {
        return 'ResponsiveImages';
    }

    /**
     * Returns an array of Twig filters, used in Twig templates via:
     *
     *      {{ entry.content | responsiveImages }}
     *
     * @return array
     */
    public function getFilters() : array
    {
        return [
            new \Twig_SimpleFilter(
                'responsiveImages',
                [$this, 'overrideHTML'],
                array(
                    'is_safe' => array('html'),
                    'is_variadic' => true,
                )
            ),
        ];
    }

    /**
     * Returns an array of Twig functions, used in Twig templates via:
     *
     *      {% set image = responsiveImage(asset, { options }) %}
     *      <img src="{{ image.src }}" srcset="{{ image.srcset }}">
     *
     * @return array
     */
    public function getFunctions() : array
    {
        return [
            new \Twig_SimpleFunction(
                'responsiveImage',
                [$this, 'outputResponsiveImage'],
                array(
                    'is_safe' => array('html'),
                )
            ),
        ];
    }

    public function overrideHTML($html, array $options = array())
    {
        $options = $options[0];
        $options['sizes'] = $options['sizes'] ?? '100vw';

        $isRedactor = false;

        if ($html instanceof craft\redactor\FieldData) {
            $isRedactor = true;
        }

        $document = new \DOMDocument();
        @$document->loadHTML($isRedactor ? mb_convert_encoding($html->getRawContent(), 'HTML-ENTITIES', 'UTF-8') : $html);

        $images = $document->getElementsByTagName('img');

        for ($i = 0; $i < $images->count(); $i++) {
            $tag = $images->item($i);
            $src = $tag->getAttribute('src');

            if ($isRedactor) {
                $id = (int) explode(':', $src)[1];
                $image = craft\elements\Asset::findOne($id);
            } else {
                $image = craft\elements\Asset::findOne(['filename' => basename($src)]);
            }

            if ($image) {
                $responsive = $this->outputResponsiveImage($image, $options);

                $tag->setAttribute('src', $responsive->src);
                $tag->setAttribute('srcset', $responsive->srcset);
                $tag->setAttribute('sizes', $options['sizes']);
            }
        }

        $newHTML = preg_replace('~<(?:!DOCTYPE|/?(?:html|head|body))[^>]*>\s*~i', '', $document->saveHTML());

        if ($isRedactor) {
            return new craft\redactor\FieldData($newHTML);
        }

        return $newHTML;
    }

    /**
     * Generate src & srcset for any image
     *
     * @param \craft\elements\Asset $image
     * @param array $options
     *
     * @return ResponsiveImage
     */
    public function outputResponsiveImage(craft\elements\Asset $image, array $options = array())
    {
        $settings = \gentsagency\responsiveimages\ResponsiveImages::getInstance()->settings;

        // Parse options
        $sizes = !empty($options['sizes']) ? $options['sizes'] : '100vw';
        $quality = !empty($options['quality']) ? (int) $options['quality'] : 60;
        $ratio = !empty($options['aspectRatio']) ? (float) $options['aspectRatio'] : -1;

        $widths = $settings->widths;

        if (!empty($options['widths'])) {
            $widths = array_unique(array_reduce($options['widths'], function ($carry, $width) {
                array_push($carry, $width, $width * 2, $width * 3);
                return $carry;
            }, array()), SORT_NUMERIC);

            sort($widths);
        }

        $responsiveImage = new ResponsiveImage();
        $responsiveImage->setAttributes($image->attributes);

        foreach ($widths as $width) {
            $params = array(
                'mode' => 'fill',
                'width' => $width,
                'quality' => $quality,
            );

            if ($ratio > 0) {
                $height = floor($width / $ratio);
                $params = array(
                    'mode' => 'crop',
                    'width' => $width,
                    'height' => $height,
                    'quality' => $quality,
                );
            }

            $transformer = 'craft';

            if (!empty($settings->volumes[$image->volume->id]) && !empty($settings->volumes[$image->volume->id]['imgix']['domain'])) {
                if (!isset($this->imgixBuilders[$image->volume->id])) {
                    $domain = $settings->volumes[$image->volume->id]['imgix']['domain'];
                    $signKey = $settings->volumes[$image->volume->id]['imgix']['signKey'] ?? null;

                    $imgixBuilder = new \Imgix\UrlBuilder($domain);
                    $imgixBuilder->setUseHttps(true);

                    if (!empty($signKey)) {
                        $imgixBuilder->setSignKey($signKey);
                    }

                    $this->imgixBuilders[$image->volume->id] = $imgixBuilder;
                }

                $transformer = 'imgix';

                if (isset($options['imgix'])) {
                    $params['imgix'] = $options['imgix'];
                }
            }

            $url = $this->generateImageURL($image, $params, $transformer);

            $responsiveImage->addSource($width, $url);
        }

        return $responsiveImage;
    }

    public function generateImageURL(craft\elements\Asset $image, array $params, string $transformer = 'craft') : string
    {
        if ($transformer === 'imgix') {
            return $this->generateImageURLWithImgix($image, $params);
        }

        return $this->generateImageURLWithCraft($image, $params);
    }

    public function generateImageURLWithCraft(craft\elements\Asset $image, array $params) : string
    {
        return $image->getURL($params);
    }

    public function generateImageURLWithImgix(craft\elements\Asset $image, array $params) : string
    {
        $mapped = !empty($params['imgix']) && is_array($params['imgix']) ? $params['imgix'] : array();

        if ($params['mode'] === 'fit') {
            $mapped['fit'] = 'clip';
        } elseif ($params['mode'] === 'crop') {
            $mapped['fit'] = 'crop';

            $focalPoint = $image->getFocalPoint();

            if ($focalPoint['x'] !== 0.5 || $focalPoint['y'] !== 0.5) {
                // If the default focal point was changed by the admin,
                // pass it to Imgix
                $mapped['crop'] = 'focalpoint';
                $mapped['fp-x'] = $focalPoint['x'];
                $mapped['fp-y'] = $focalPoint['y'];
            } else {
                // Enable Imgix default face detection & entropy algorithm
                $mapped['crop'] = 'faces,entropy';
            }
        }

        $mapped['w'] = $params['width'];

        if (!empty($params['height'])) {
            $mapped['h'] = $params['height'];
        }

        $mapped['q'] = $params['quality'];
        $mapped['ch'] = 'Save-Data';
        $mapped['auto'] = 'format,compress';

        return $this->imgixBuilders[$image->volume->id]->createURL($image->getPath(), $mapped);
    }
}
