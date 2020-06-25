<?php

namespace Willtj\PhpDraftjsHtml;

class StyleToCss
{
    const VENDOR_PREFIX = '/^(moz|ms|o|webkit)-/';

    // Lifted from:
    // https://github.com/facebook/react/blob/ab4ddf64939aebbbc8d31be1022efd56e834c95c/src/renderers/dom/shared/CSSProperty.js
    protected $unitlessNumbers = [
        'animationIterationCount' => true,
        'borderImageOutset' => true,
        'borderImageSlice' => true,
        'borderImageWidth' => true,
        'boxFlex' => true,
        'boxFlexGroup' => true,
        'boxOrdinalGroup' => true,
        'columnCount' => true,
        'flex' => true,
        'flexGrow' => true,
        'flexPositive' => true,
        'flexShrink' => true,
        'flexNegative' => true,
        'flexOrder' => true,
        'gridRow' => true,
        'gridRowEnd' => true,
        'gridRowSpan' => true,
        'gridRowStart' => true,
        'gridColumn' => true,
        'gridColumnEnd' => true,
        'gridColumnSpan' => true,
        'gridColumnStart' => true,
        'fontWeight' => true,
        'lineClamp' => true,
        'lineHeight' => true,
        'opacity' => true,
        'order' => true,
        'orphans' => true,
        'tabSize' => true,
        'widows' => true,
        'zIndex' => true,
        'zoom' => true,
        // SVG-related properties
        'fillOpacity' => true,
        'floodOpacity' => true,
        'stopOpacity' => true,
        'strokeDasharray' => true,
        'strokeDashoffset' => true,
        'strokeMiterlimit' => true,
        'strokeOpacity' => true,
        'strokeWidth' => true,
    ];

    /**
     * Convert a style array to a string for an HTML attribute.
     */
    public function convert(array $styleDescr): string
    {
        return implode('; ', array_map(function ($key) use ($styleDescr) {
            $styleValue = $this->processStyleValue($key, $styleDescr[$key]);
            $styleName = $this->processStyleName($key);

            return "{$styleName}: {$styleValue}";
        }, array_keys($styleDescr)));
    }

    protected function processStyleValue(string $key, string $value): string
    {
        if (!is_numeric($value) || '0' === $value || !empty($this->unitlessNumbers[$key])) {
            return $value;
        } else {
            return $value . 'px';
        }
    }

    /**
     * Based on https://github.com/facebook/react/blob/master/src/renderers/dom/shared/CSSPropertyOperations.js.
     */
    protected function processStyleName(string $name): string
    {
        return preg_replace(self::VENDOR_PREFIX, '-$1-', strtolower(preg_replace('/([A-Z])/', '-$1', $name)));
    }
}
