<?php

declare(strict_types=1);

namespace Shel\Neos\CrossContentRepositoryReferences\Dto;

use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;

/**
 * Minimal, serializable reference to a node in another content repository.
 *
 * Unlike a full {@see \Neos\ContentRepository\Core\SharedModel\Node\NodeAddress}
 * this only carries the {@see ContentRepositoryId} and {@see NodeAggregateId} -
 * the workspace and dimension space point are intentionally not stored, because
 * they should be resolved from the rendering context (the context node) when
 * the reference is loaded in Fusion.
 *
 * The serialized JSON form is what gets stored on a node property and what is
 * used as the `value` of a {@see ReferenceOption}.
 */
final readonly class CrossContentRepositoryReference implements \JsonSerializable
{
    public function __construct(
        public ContentRepositoryId $contentRepositoryId,
        public NodeAggregateId $nodeAggregateId,
    ) {
    }

    public static function fromNode(Node $node): self
    {
        return new self($node->contentRepositoryId, $node->aggregateId);
    }

    /**
     * @param array<mixed,mixed> $array
     */
    public static function fromArray(array $array): ?self
    {
        $contentRepositoryIdValue = $array['contentRepositoryId'] ?? null;
        $nodeAggregateIdValue = $array['nodeAggregateId'] ?? null;
        if (!is_string($contentRepositoryIdValue) || !is_string($nodeAggregateIdValue)) {
            return null;
        }
        try {
            return new self(
                ContentRepositoryId::fromString($contentRepositoryIdValue),
                NodeAggregateId::fromString($nodeAggregateIdValue),
            );
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    public static function fromString(string $jsonString): ?self
    {
        try {
            $decoded = json_decode($jsonString, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($decoded)) {
            return null;
        }
        return self::fromArray($decoded);
    }

    /**
     * @return array{__identity: string, contentRepositoryId: string, nodeAggregateId: string}
     */
    public function jsonSerialize(): array
    {
        return [
            # The identity is currently required for the Neos.Ui to match the options
            '__identity' => $this->contentRepositoryId->value . ':' . $this->nodeAggregateId->value,
            'contentRepositoryId' => $this->contentRepositoryId->value,
            'nodeAggregateId' => $this->nodeAggregateId->value,
        ];
    }
}
