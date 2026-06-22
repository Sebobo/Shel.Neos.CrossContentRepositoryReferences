<?php

declare(strict_types=1);

namespace Shel\Neos\CrossContentRepositoryReferences\DataSource;

use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\NodeType\NodeTypeNames;
use Neos\ContentRepository\Core\Projection\ContentGraph\AbsoluteNodePath;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindDescendantNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\NodeType\NodeTypeCriteria;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\Nodes;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\NodeLabel\NodeLabelGeneratorInterface;
use Neos\Neos\Service\DataSource\AbstractDataSource;
use Shel\Neos\CrossContentRepositoryReferences\Dto\CrossContentRepositoryReference;
use Shel\Neos\CrossContentRepositoryReferences\Dto\ReferenceOption;
use Shel\Neos\CrossContentRepositoryReferences\Service\DimensionTranslator;

/**
 * Data source that allows selecting nodes from any content repository via the
 * inspector, in contrast to the built-in "reference" / "references" property
 * types which are confined to the content repository of the edited node.
 *
 * The starting point is configured via the `startingPoint` argument using the
 * pattern `/<contentRepositoryId>/<RootNodeType>/<siteName>`, e.g.
 * `/<Neos.Neos:Sites>/my-site` resolved against the `default` content
 * repository would be configured as `/default/<Neos.Neos:Sites>/my-site`.
 *
 * Each selectable option's `value` is a serialized
 * {@see CrossContentRepositoryReference} (JSON), containing only the content
 * repository id and node aggregate id. The workspace and dimension space point
 * are not stored - they are resolved from the rendering context (the context
 * node) when the reference is loaded in Fusion via the
 * `Shel.Neos.CrossContentRepositoryReferences.Node` Eel helper.
 */
class ReferencesDataSource extends AbstractDataSource
{
    /**
     * @inheritdoc
     */
    protected static $identifier = 'cross-content-repository-references';

    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    protected DimensionTranslator $dimensionTranslator;

    #[Flow\Inject]
    protected NodeLabelGeneratorInterface $nodeLabelGenerator;

    /**
     * @param Node|null $node The node currently being edited
     * @param array<string,mixed> $arguments Additional arguments from the editor configuration
     * @return list<ReferenceOption>
     */
    public function getData(
        ?Node $node = null,
        array $arguments = []
    ): array {
        if (!$node) {
            return [];
        }

        $startingPoint = is_string($arguments['startingPoint'] ?? null) ? $arguments['startingPoint'] : '';
        if ($startingPoint === '') {
            return [];
        }

        $parsedStartingPoint = $this->parseStartingPoint($startingPoint);
        if ($parsedStartingPoint === null) {
            return [];
        }

        $contentRepositoryId = $parsedStartingPoint['contentRepositoryId'];
        $absolutePath = $parsedStartingPoint['absolutePath'];

        $workspaceName = $node->workspaceName;
        $dimensionSpacePoint = $this->resolveDimensionSpacePoint($node, $contentRepositoryId);

        try {
            $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);
            $subgraph = $contentRepository->getContentSubgraph($workspaceName, $dimensionSpacePoint);
        } catch (\Throwable) {
            return [];
        }

        $startingNode = $subgraph->findNodeByAbsolutePath($absolutePath);
        if ($startingNode === null) {
            return [];
        }

        $nodeTypes = $arguments['nodeTypes'] ?? 'Neos.Neos:Document';
        if (is_array($nodeTypes)) {
            $nodeTypeNames = [];
            foreach ($nodeTypes as $nodeType) {
                if (is_string($nodeType)) {
                    $nodeTypeNames[] = $nodeType;
                }
            }
            $nodeTypeCriteria = NodeTypeCriteria::createWithAllowedNodeTypeNames(
                NodeTypeNames::fromStringArray($nodeTypeNames)
            );
        } else {
            $nodeTypeCriteria = NodeTypeCriteria::fromFilterString(
                is_string($nodeTypes) ? $nodeTypes : 'Neos.Neos:Document'
            );
        }

        $searchTerm = is_string($arguments['searchTerm'] ?? null) && $arguments['searchTerm'] !== ''
            ? $arguments['searchTerm']
            : null;

        $filter = FindDescendantNodesFilter::create(
            nodeTypes: $nodeTypeCriteria,
            searchTerm: $searchTerm,
        );

        $descendants = $subgraph->findDescendantNodes($startingNode->aggregateId, $filter);

        return $this->buildOptions($descendants);
    }

    /**
     * Parse the configured starting point into a content repository id and an absolute node path.
     *
     * Expected format: `/<contentRepositoryId>/<RootNodeType>/<siteName>` e.g.
     * `/default/<Neos.Neos:Sites>/my-site`
     *
     * @return array{contentRepositoryId: ContentRepositoryId, absolutePath: AbsoluteNodePath}|null
     */
    private function parseStartingPoint(string $startingPoint): ?array
    {
        // Strip a leading slash and the content repository id (the first path segment).
        $trimmed = ltrim($startingPoint, '/');
        $firstSlashPosition = strpos($trimmed, '/');
        if ($firstSlashPosition === false || $firstSlashPosition === 0) {
            return null;
        }

        $contentRepositoryIdValue = substr($trimmed, 0, $firstSlashPosition);
        $absolutePathString = substr($trimmed, $firstSlashPosition);

        // The remaining string must be a valid absolute node path like
        // `/<Neos.Neos:Sites>/my-site`.
        if (!AbsoluteNodePath::patternIsMatchedByString($absolutePathString)) {
            return null;
        }

        try {
            $contentRepositoryId = ContentRepositoryId::fromString($contentRepositoryIdValue);
            $absolutePath = AbsoluteNodePath::fromString($absolutePathString);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return [
            'contentRepositoryId' => $contentRepositoryId,
            'absolutePath' => $absolutePath,
        ];
    }

    /**
     * Resolve the dimension space point used to load nodes in the target content
     * repository. Translates the context node's dimensions to be compatible with
     * the target CR's dimension configuration.
     */
    private function resolveDimensionSpacePoint(Node $node, ContentRepositoryId $targetCrId): DimensionSpacePoint
    {
        return $this->dimensionTranslator->translateDimensionSpacePoint(
            $targetCrId,
            $node->dimensionSpacePoint,
        );
    }

    /**
     * @param Nodes $nodes
     * @return list<ReferenceOption>
     */
    private function buildOptions(Nodes $nodes): array
    {
        $options = [];
        foreach ($nodes as $descendant) {
            $options[] = new ReferenceOption(
                $this->nodeLabelGenerator->getLabel($descendant),
                CrossContentRepositoryReference::fromNode($descendant),
                $descendant->nodeTypeName,
            );
        }
        return $options;
    }
}
