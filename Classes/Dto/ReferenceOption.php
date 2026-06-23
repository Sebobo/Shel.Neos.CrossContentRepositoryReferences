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
 */
final readonly class ReferenceOption implements \JsonSerializable
{
    public function __construct(
        public string $label,
        public CrossContentRepositoryReference $value,
        public NodeTypeName $nodeType,
    ) {
    }

    /**
     * @return array{label: string, value: CrossContentRepositoryReference, nodeType: NodeTypeName}
     */
    public function jsonSerialize(): array
    {
        return [
            'label' => $this->label,
            'value' => $this->value,
            'nodeType' => $this->nodeType,
        ];
    }
}
