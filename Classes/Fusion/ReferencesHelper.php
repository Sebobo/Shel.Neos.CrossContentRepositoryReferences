<?php

declare(strict_types=1);

namespace Shel\Neos\CrossContentRepositoryReferences\Fusion;

use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Eel\ProtectedContextAwareInterface;
use Neos\Flow\Annotations as Flow;
use Shel\Neos\CrossContentRepositoryReferences\Dto\CrossContentRepositoryReference;
use Shel\Neos\CrossContentRepositoryReferences\Service\DimensionTranslator;

/**
 * Eel helper that resolves nodes from serialized
 * {@see CrossContentRepositoryReference} strings, allowing Fusion to render
 * nodes from any content repository.
 *
 * The workspace and dimension space point are not part of the stored reference;
 * instead they are taken from a context node (typically the currently rendered
 * node) so that the reference is resolved in the same editing/rendering context.
 *
 * Register and use via Eel, e.g.:
 *
 *     prototype(Vendor.Site:Component) {
 *         referenceNode = ${Shel.Neos.CrossContentRepositoryReferences.node(node, node.properties.crossReference)}
 *     }
 *
 */
class ReferencesHelper implements ProtectedContextAwareInterface
{
    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    protected DimensionTranslator $dimensionTranslator;

    /**
     * Resolve a single node from a serialized {@see CrossContentRepositoryReference}
     * JSON string, using the workspace and dimension space point of the given
     * context node.
     *
     * @param Node|null $contextNode The node whose workspace and dimension space point are used to resolve the reference
     */
    public function node(?Node $contextNode, ?CrossContentRepositoryReference $reference): ?Node
    {
        if ($contextNode === null || $reference === null) {
            return null;
        }
        return $this->resolveNode($contextNode, $reference);
    }

    /**
     * Resolve multiple nodes from an array of serialized
     * {@see CrossContentRepositoryReference} JSON strings, using the workspace
     * and dimension space point of the given context node.
     *
     * The order of the input array is preserved; unresolvable entries are skipped.
     *
     * @param Node|null $contextNode The node whose workspace and dimension space point are used to resolve the references
     * @param list<CrossContentRepositoryReference|null> $references
     * @return array<int,Node>
     */
    public function nodes(?Node $contextNode, array $references): array
    {
        if ($contextNode === null) {
            return [];
        }
        $nodes = [];
        foreach ($references as $reference) {
            $node = $this->node($contextNode, $reference);
            if ($node !== null) {
                $nodes[] = $node;
            }
        }
        return $nodes;
    }

    private function resolveNode(Node $contextNode, CrossContentRepositoryReference $reference): ?Node
    {
        try {
            $contentRepository = $this->contentRepositoryRegistry->get($reference->contentRepositoryId);
            $dimensionSpacePoint = $this->dimensionTranslator->translateDimensionSpacePoint(
                $reference->contentRepositoryId,
                $contextNode->dimensionSpacePoint,
            );
            $subgraph = $contentRepository->getContentSubgraph(
                $contextNode->workspaceName,
                $dimensionSpacePoint
            );
            return $subgraph->findNodeById($reference->nodeAggregateId);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * All methods are safe to be called from Fusion.
     */
    public function allowsCallOfMethod($methodName): bool
    {
        return true;
    }
}
