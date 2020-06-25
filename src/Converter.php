<?php

namespace Willtj\PhpDraftjsHtml;

use DOMDocument;
use DOMElement;
use DOMNode;
use Prezly\DraftPhp\Model\ContentBlock;
use Prezly\DraftPhp\Model\ContentState;
use Prezly\DraftPhp\Model\EntityInstance;

class Converter
{
    const BLOCK_TYPE_UNSTYLED = 'unstyled';
    const BLOCK_TYPE_HEADER_ONE = 'header-one';
    const BLOCK_TYPE_HEADER_TWO = 'header-two';
    const BLOCK_TYPE_HEADER_THREE = 'header-three';
    const BLOCK_TYPE_HEADER_FOUR = 'header-four';
    const BLOCK_TYPE_HEADER_FIVE = 'header-five';
    const BLOCK_TYPE_HEADER_SIX = 'header-six';
    const BLOCK_TYPE_UNORDERED_LIST_ITEM = 'unordered-list-item';
    const BLOCK_TYPE_ORDERED_LIST_ITEM = 'ordered-list-item';
    const BLOCK_TYPE_BLOCKQUOTE = 'blockquote';
    const BLOCK_TYPE_PULLQUOTE = 'pullquote';
    const BLOCK_TYPE_CODE = 'code-block';
    const BLOCK_TYPE_ATOMIC = 'atomic';

    const ENTITY_TYPE_LINK = 'LINK';
    const ENTITY_TYPE_IMAGE = 'IMAGE';
    const ENTITY_TYPE_EMBED = 'embed';

    const BREAK_LINE = '<br>';
    const DATA_ATTRIBUTE = '/^data-([a-z0-9-]+)$/';

    const ATTR_NAME_MAP = [
        'acceptCharset' => 'accept-charset',
        'className' => 'class',
        'htmlFor' => 'for',
        'httpEquiv' => 'http-equiv',
    ];

    const INLINE_STYLE_BOLD = 'BOLD';
    const INLINE_STYLE_CODE = 'CODE';
    const INLINE_STYLE_ITALIC = 'ITALIC';
    const INLINE_STYLE_STRIKETHROUGH = 'STRIKETHROUGH';
    const INLINE_STYLE_UNDERLINE = 'UNDERLINE';

    protected $styleMap = [
        self::INLINE_STYLE_BOLD => ['element' => 'strong'],
        self::INLINE_STYLE_CODE => ['element' => 'code'],
        self::INLINE_STYLE_ITALIC => ['element' => 'em'],
        self::INLINE_STYLE_STRIKETHROUGH => ['element' => 'del'],
        self::INLINE_STYLE_UNDERLINE => ['element' => 'u'],
    ];

    // Order: inner-most style to outer-most.
    // Examle: <em><strong>foo</strong></em>
    protected $styleOrder = [
        self::INLINE_STYLE_BOLD,
        self::INLINE_STYLE_ITALIC,
        self::INLINE_STYLE_UNDERLINE,
        self::INLINE_STYLE_STRIKETHROUGH,
        self::INLINE_STYLE_CODE
    ];

    // Map entity data to element attributes.
    protected $allowedAttributes = [
        self::ENTITY_TYPE_LINK => [
            'url' => 'href',
            'href' => 'href',
            'rel' => 'rel',
            'target' => 'target',
            'title' => 'title',
            'className' => 'class',
        ],
        self::ENTITY_TYPE_IMAGE => [
            'src' => 'src',
            'height' => 'height',
            'width' => 'width',
            'alt' => 'alt',
            'className' => 'class',
        ],
    ];

    /**
     * @var ContentState
     */
    protected $state;

    /**
     * @var array
     */
    protected $output = [];

    /**
     * @var string
     */
    protected $defaultBlockTag = 'p';

    /**
     * @var DOMDocument
     */
    protected $doc;

    /**
     * @var DOMNode
     */
    protected $currentContainer;

    /**
     * @var int
     */
    protected $currentDepth = 0;

    /**
     * @var StyleToCss
     */
    protected $styleToCss;

    /**
     * @param  ContentState $state
     * @return self
     */
    public function setState(ContentState $state): self
    {
        $this->state = $state;

        return $this;
    }

    /**
     * Update styles in the map by passing an array like ['BOLD' => ['element' => 'b']]
     *
     * @param array $properties
     * @return self
     */
    public function updateStyleMap(array $styleMap): self
    {
        foreach ($styleMap as $style => $properties) {
            if (! array_key_exists($style, $this->styleMap)) {
                $this->styleOrder[] = $style;
            }

            $this->styleMap[$style] = $properties;
        }

        return $this;
    }

    /**
     * Convert the state to an HTML string
     *
     * @return string
     */
    public function toHtml(): string
    {
        if (! $this->state) {
            return '';
        }

        $this->doc = new DOMDocument;
        $rootElement = $this->doc->createElement('div');
        $this->doc->appendChild($rootElement);
        $this->currentContainer = $rootElement;

        foreach ($this->state->blocks as $block) {
            $this->processBlock($block);
        }

        $output = [];

        foreach ($rootElement->childNodes as $node) {
            $output[] = $this->doc->saveXML($node);
        }

        return implode('', $output);
    }

    /**
     * Convert a single block to an HTML string
     *
     * @param ContentBlock $block
     * @return void
     */
    protected function processBlock(ContentBlock $block): void
    {
        $newWrapperTag = $this->getWrapperTag($block->type);

        if ($newWrapperTag && ($this->currentContainer->nodeName !== $newWrapperTag || $block->depth > $this->currentDepth)) {
            $wrapperElement = $this->doc->createElement($newWrapperTag);
            $wrapperElement->isWrapper = true;

            if ($block->depth > $this->currentDepth) {
                $this->currentContainer->lastChild->appendChild($wrapperElement);
            } else {
                $this->currentContainer->appendChild($wrapperElement);
            }

            $this->currentContainer = $wrapperElement;
        } elseif ($block->depth < $this->currentDepth) {
            $this->currentContainer = $this->currentContainer->parentNode->parentNode;
        }

        $this->currentDepth = $block->depth;

        $element = $this->createElementForBlock($block);

        $this->currentContainer->appendChild($element);
    }

    /**
     * @param ContentBlock $block
     * @return DOMElement
     */
    protected function createElementForBlock(ContentBlock $block): DOMElement
    {
        $attributes = $this->getBlockAttributes($block);

        // Normalize `className` -> `class`, etc.
        $attributes = $this->normalizeAttributes($attributes);

        if (isset($attributes['style'])) {
            $attributes['style'] = $this->styleToCSS->convert((array) $attributes['style']);
        }

        $tags = $this->getTagsForBlock($block->type);

        $rootElement = $this->doc->createElement(array_shift($tags));

        foreach ($attributes as $name => $value) {
            $rootElement->setAttribute($name, $value);
        }

        $element = $rootElement;

        foreach ($tags as $innerTag) {
            $innerElement = $this->doc->createElement($innerTag);
            $element->appendChild($innerElement);
            $element = $innerElement;
        }

        return $this->renderBlockContent($block, $rootElement);
    }

    /**
     * @param ContentBlock $block
     * @param DOMElement $element
     * @return DOMElement
     */
    protected function renderBlockContent(ContentBlock $block, DOMElement $element): DOMElement
    {
        if ($block->text === '') {
            // Prevent element collapse if completely empty.
            return self::BREAK_LINE;
        }

        $entityRanges = $this->getEntityRanges($this->preserveWhitespace($block->text), $block->characterList);

        foreach ($entityRanges as $range) {
            $entityKey = $range['key'];
            $styleRanges = $range['styleRanges'];
            $styleRangeNodes = [];

            foreach ($styleRanges as $styleRange) {
                $text = $this->encodeContent($styleRange['text']);
                $styleSet = $styleRange['styles'] ?? [];
                $styleNodes = [];

                foreach (array_reverse($this->styleOrder) as $styleName) {
                    // If our block type is CODE then don't wrap inline code elements.
                    if ($styleName === self::INLINE_STYLE_CODE && $block->type === self::BLOCK_TYPE_CODE) {
                        continue;
                    }

                    if ($styleSet && in_array($styleName, $styleSet)) {
                        $inlineStyle = $this->styleMap[$styleName];
                        $tag = $inlineStyle['element'] ?? 'span';

                        // Normalize `className` -> `class`, etc.
                        $attributes = $this->normalizeAttributes($inlineStyle['attributes'] ?? []);

                        if (isset($inlineStyle['style']) && !empty($inlineStyle['style'])) {
                            $attributes['style'] = $this->styleToCss((array) $inlineStyle['style']);
                        }

                        $node = $this->doc->createElement($tag);

                        foreach ($attributes as $name => $value) {
                            $node->setAttribute($name, $value);
                        }

                        $styleNodes[] = $node;
                    }
                }

                if ($numStyleNodes = count($styleNodes)) {
                    for ($i = 0; $i < $numStyleNodes - 1; $i++) {
                        $styleNodes[$i]->appendChild($styleNodes[$i + 1]);
                        $node = $styleNodes[$i];
                    }

                    $styleNodes[$numStyleNodes - 1]->textContent = $text;
                } else {
                    $node = $this->doc->createTextNode($text);
                }

                $styleRangeNodes[] = $this->addCustomInlineStyles($node, $styleSet);
            }

            $entity = isset($entityKey) ? $this->state->getEntity($entityKey) : null;

            if ($entity) {
                $entityElement = $this->buildEntityElement($entity, $styleRangeNodes);

                if ($entityElement) {
                    $element->appendChild($entityElement);
                }
            } else {
                foreach ($styleRangeNodes as $node) {
                    $element->appendChild($node);
                }
            }
        }

        return $element;
    }

    /**
     * @param string $text
     * @param array $characterMetaList
     * @return array
     */
    protected function getEntityRanges(string $text, array $characterMetaList): array
    {
        $charEntity = null;
        $prevCharEntity = null;
        $ranges = [];
        $rangeStart = 0;

        foreach (\str_split($text) as $i => $char) {
            $prevCharEntity = $charEntity;

            $meta = $characterMetaList[$i] ?? null;
            $charEntity = $meta ? $meta->entity : null;

            if ($i > 0 && $charEntity !== $prevCharEntity) {
                $ranges[] = [
                    'key' => $prevCharEntity,
                    'styleRanges' => $this->getStyleRanges(
                        \substr($text, $rangeStart, $i - $rangeStart),
                        array_slice($characterMetaList, $rangeStart, $i - $rangeStart)
                    )
                ];

                $rangeStart = $i;
            }
        }

        $ranges[] = [
            'key' => $charEntity,
            'styleRanges' => $this->getStyleRanges(substr($text, $rangeStart), array_slice($characterMetaList, $rangeStart)),
        ];

        return $ranges;
    }

    /**
     * @param string $text
     * @param array $charMetaList
     * @return array
     */
    protected function getStyleRanges(string $text, array $charMetaList): array
    {
        $charStyle = [];
        $prevCharStyle = [];
        $ranges = [];
        $rangeStart = 0;

        foreach (\str_split($text) as $i => $char) {
            $prevCharStyle = $charStyle;
            $meta = $charMetaList[$i] ?? null;
            $charStyle = $meta ? $meta->style : [];

            if ($i > 0 && $charStyle !== $prevCharStyle) {
                $ranges[] = [
                    'text' => substr($text, $rangeStart, $i - $rangeStart),
                    'styles' => $prevCharStyle
                ];

                $rangeStart = $i;
            }
        }

        $ranges[] = [
            'text' => substr($text, $rangeStart),
            'styles' => $charStyle
        ];

        return $ranges;
    }

    /**
     * @param EntityInstance $entity
     * @param array $childNodes
     * @return DOMElement|null
     */
    protected function buildEntityElement(EntityInstance $entity, array $childNodes): ?DOMElement
    {
        $attributes = $this->getEntityAttributes($entity);

        switch ($entity->type) {
            case self::ENTITY_TYPE_IMAGE:
                $element = $this->doc->createElement('img');
                break;

            case self::ENTITY_TYPE_LINK:
                $element = $this->doc->createElement('a');

                foreach ($childNodes as $node) {
                    $element->appendChild($node);
                }

                break;

            default:
                return null;
        }

        foreach ($this->getEntityAttributes($entity) as $name => $value) {
            $element->setAttribute($name, $value);
        }

        return $element;
    }

    /**
     * @param EntityInstance $entity
     * @return array
     */
    protected function getEntityAttributes(EntityInstance $entity): array
    {
        $attributes = [];

        if (! $attributesMap = $this->allowedAttributes[$entity->type]) {
            return $attributes;
        }

        foreach ($entity->data as $key => $value) {
            if (isset($value) && array_key_exists($key, $attributesMap)) {
                $attribute = $attributesMap[$key];
                $attributes[$attribute] = $value;
            } elseif ($this->isDataAttribute($key)) {
                $attributes[$key] = $value;
            }
        }

        return $attributes;
    }

    /**
     * Prevent leading/trailing/consecutive whitespace collapse.
     *
     * @param string $text
     * @return string
     */
    protected function preserveWhitespace(string $text): string
    {
        $length = \strlen($text);
        $newText = '';

        foreach (str_split($text) as $i => $char) {
            if ($char=== ' ' &&
                ($i === 0 || $i === $length - 1 || substr($text, $i - 1, 1) === ' ')
            ) {
                $newText .= '\xA0';
            } else {
                $newText .= $char;
            }
        }

        return $newText;
    }

    /**
     * @param array $styles
     * @return string
     */
    protected function styleToCss(array $styles): string
    {
        if (! isset($this->styleToCss)) {
            $this->styleToCss = new StyleToCss;
        }

        return $this->styleToCss->convert($styles);
    }

    /**
     * Get attributes that should be applied to the given block
     *
     * @param ContentBlock $block
     * @return array
     */
    protected function getBlockAttributes(ContentBlock $block): array
    {
        return [];
    }

    /**
     * @param array $attributes
     * @return array
     */
    protected function normalizeAttributes(array $attributes): array
    {
        if (! $attributes) {
            return $attributes;
        }

        $normalized = [];

        foreach ($attributes as $name => $value) {
            if (isset(self::ATTR_NAME_MAP[$name])) {
                $name = self::ATTR_NAME_MAP[$name];
            }

            $normalized[$name] = $value;
        }

        return $normalized;
    }

    /**
     * @param string $blockType
     * @return boolean
     */
    protected function canHaveDepth(string $blockType): bool
    {
        switch ($blockType) {
            case self::BLOCK_TYPE_UNORDERED_LIST_ITEM:
            case self::BLOCK_TYPE_ORDERED_LIST_ITEM:
                return true;
            default:
                return false;
        }
    }

    /**
     * @param string $attribute
     * @return boolean
     */
    protected function isDataAttribute(string $attribute): bool
    {
        return (bool) \preg_match(self::DATA_ATTRIBUTE, $attribute);
    }

    /**
     * @param string $text
     * @return string
     */
    protected function encodeContent(string $text): string
    {
        return \str_replace(
            ['&', '<', '>', '\xA0', '\n'],
            ['&amp;', '&lt;', '&gt;', '&nbsp;', self::BREAK_LINE . '\n'],
            $text
        );
    }

    /**
     * @param DOMNode $node
     * @param array $styleSet
     * @return DOMNode
     */
    protected function addCustomInlineStyles(DOMNode $node, array $styleSet): DOMNode
    {
        return $node;
    }

    /**
     * Get HTML tags that the given block type should be converted to
     *
     * @param string $blockType
     * @return array
     */
    protected function getTagsForBlock(string $blockType): array
    {
        switch ($blockType) {
            case self::BLOCK_TYPE_HEADER_ONE:
                return ['h1'];
            case self::BLOCK_TYPE_HEADER_TWO:
                return ['h2'];
            case self::BLOCK_TYPE_HEADER_THREE:
                return ['h3'];
            case self::BLOCK_TYPE_HEADER_FOUR:
                return ['h4'];
            case self::BLOCK_TYPE_HEADER_FIVE:
                return ['h5'];
            case self::BLOCK_TYPE_HEADER_SIX:
                return ['h6'];
            case self::BLOCK_TYPE_UNORDERED_LIST_ITEM:
            case self::BLOCK_TYPE_ORDERED_LIST_ITEM:
                return ['li'];
            case self::BLOCK_TYPE_BLOCKQUOTE:
                return ['blockquote'];
            case self::BLOCK_TYPE_CODE:
                return ['pre', 'code'];
            case self::BLOCK_TYPE_ATOMIC:
                return ['figure'];
            default:
                return $this->defaultBlockTag === null ? [] : [$this->defaultBlockTag];
        }
    }

    /**
     * Get the tag that should be used to wrap the given block type
     *
     * @param string $blockType
     * @return string|null
     */
    protected function getWrapperTag(string $blockType): ?string
    {
        switch ($blockType) {
            case self::BLOCK_TYPE_UNORDERED_LIST_ITEM:
                return 'ul';
            case self::BLOCK_TYPE_ORDERED_LIST_ITEM:
                return 'ol';
            default:
                return null;
        }
    }
}
