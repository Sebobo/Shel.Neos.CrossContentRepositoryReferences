<?php

declare(strict_types=1);

namespace Shel\Neos\CrossContentRepositoryReferences\Dto;

use Neos\ContentRepository\Core\NodeType\NodeTypeName;

/**
 * Represents a single selectable option returned by the
 * {@see \Shel\Neos\CrossContentRepositoryReferences\DataSource\ReferencesDataSource}.
 *
 * The `value` is a serialized {@see CrossContentRepositoryReference} (JSON),
 * which contains only the content repository id and node aggregate id - the
 * workspace and dimension space point are resolved from the rendering context
 * (the context node) when the reference is loaded in Fusion.
 *
 * `preview` is an optional thumbnail URI (e.g. for a node's image property)
 * that the inspector editor can render next to the label.
 *
 * `uri` is an optional frontend preview URL for the referenced node. When
 * present, clicking the option in the inspector navigates the content canvas
 * to that URI. Built via {@see NodeUriBuilder::previewUriFor()}.
 */
final readonly class ReferenceOption implements \JsonSerializable
{
    public function __construct(
        public string $label,
        public CrossContentRepositoryReference $value,
        public NodeTypeName $nodeType,
        public ?string $preview = null,
        public ?string $uri = null,
    ) {
    }

    /**
     * @return array{label: string, secondaryLabel: string, value: CrossContentRepositoryReference, nodeType: NodeTypeName, preview?: string, uri?: string}
     */
    public function jsonSerialize(): array
    {
        $data = [
            'label' => $this->label,
            'secondaryLabel' => $this->value->contentRepositoryId->value,
            'value' => $this->value,
            'nodeType' => $this->nodeType,
        ];
        if ($this->preview !== null) {
            $data['preview'] = $this->preview;
        }
        if ($this->uri !== null) {
            $data['uri'] = $this->uri;
        }
        return $data;
    }
}
