<?php

declare(strict_types=1);

namespace Shel\Neos\CrossContentRepositoryReferences\FlowQueryOperations;

use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Eel\FlowQuery\FlowQuery;
use Neos\Eel\FlowQuery\OperationInterface;
use Neos\Flow\Annotations as Flow;
use Shel\Neos\CrossContentRepositoryReferences\Dto\CrossContentRepositoryReference;
use Shel\Neos\CrossContentRepositoryReferences\Service\DimensionTranslator;

/**
 * "referenceNodesAcrossCR" operation working on Nodes
 *
 * This operation can be used to resolve cross-content-repository references
 * that are stored on a node property as serialized
 * {@see CrossContentRepositoryReference} JSON strings.
 *
 * The property name must be given as the first argument:
 *
 *     ${q(node).referenceNodesAcrossCR("crossReferences").property("title")}
 *
 * The property can hold a single JSON string (single reference) or an array
 * of JSON strings (multiple references). Workspace and dimension space point
 * are taken from the context node.
 */
final class ReferenceNodesAcrossCROperation implements OperationInterface
{
    /**
     * @var string
     */
    protected static $shortName = 'referenceNodesAcrossCR';

    /**
     * @var int
     */
    protected static $priority = 0;

    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    protected DimensionTranslator $dimensionTranslator;

    /**
     * @param array<int, mixed> $context
     */
    public function canEvaluate($context): bool
    {
        return count($context) === 0 || (isset($context[0]) && ($context[0] instanceof Node));
    }

    /**
     * @param array<int, mixed> $arguments
     */
    public function evaluate(FlowQuery $flowQuery, array $arguments): void
    {
        if (!isset($arguments[0]) || !is_string($arguments[0])) {
            throw new \InvalidArgumentException(
                'The property name containing the cross-content-repository reference(s) must be specified as the first argument.',
                1750675200,
            );
        }

        $propertyName = $arguments[0];
        $output = [];

        foreach ($flowQuery->getContext() as $contextNode) {
            if (!$contextNode instanceof Node) {
                continue;
            }
            /** @var mixed $propertyValue */
            $propertyValue = $contextNode->getProperty($propertyName);

            if (is_string($propertyValue)) {
                $reference = CrossContentRepositoryReference::fromJsonString($propertyValue);
                if ($reference !== null) {
                    $resolved = $this->resolveNode($contextNode, $reference);
                    if ($resolved !== null) {
                        $output[] = $resolved;
                    }
                }
            } elseif (is_array($propertyValue)) {
                foreach ($propertyValue as $item) {
                    if (is_string($item)) {
                        $reference = CrossContentRepositoryReference::fromJsonString($item);
                        if ($reference !== null) {
                            $resolved = $this->resolveNode($contextNode, $reference);
                            if ($resolved !== null) {
                                $output[] = $resolved;
                            }
                        }
                    }
                }
            }
        }

        $flowQuery->setContext($output);
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
                $dimensionSpacePoint,
            );
            return $subgraph->findNodeById($reference->nodeAggregateId);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function getShortName(): string
    {
        return 'referenceNodesAcrossCR';
    }

    public static function getPriority(): int
    {
        return 100;
    }

    public static function isFinal(): bool
    {
        return false;
    }
}
