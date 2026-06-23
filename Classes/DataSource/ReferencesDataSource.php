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
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Model\ThumbnailConfiguration;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Media\Domain\Service\AssetService;
use Neos\Media\Domain\Service\ThumbnailService;
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

    #[Flow\Inject]
    protected AssetRepository $assetRepository;

    #[Flow\Inject]
    protected AssetService $assetService;

    #[Flow\Inject]
    protected ThumbnailService $thumbnailService;

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

        $imageProperty = is_string($arguments['imageProperty'] ?? null) && $arguments['imageProperty'] !== ''
            ? $arguments['imageProperty']
            : null;
        $thumbnailWidth = isset($arguments['thumbnailWidth']) && is_numeric($arguments['thumbnailWidth'])
            ? (int)$arguments['thumbnailWidth']
            : 100;

        return $this->buildOptions($descendants, $imageProperty, $thumbnailWidth);
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
     * @param string|null $imageProperty Name of a node property holding an image/asset to render as a preview thumbnail
     * @param int $thumbnailWidth Maximum width of the generated preview thumbnail
     * @return list<ReferenceOption>
     */
    private function buildOptions(Nodes $nodes, ?string $imageProperty, int $thumbnailWidth): array
    {
        $options = [];
        foreach ($nodes as $descendant) {
            $preview = $imageProperty !== null ? $this->resolvePreviewUri($descendant, $imageProperty, $thumbnailWidth) : null;
            $options[] = new ReferenceOption(
                $this->nodeLabelGenerator->getLabel($descendant),
                CrossContentRepositoryReference::fromNode($descendant),
                $descendant->nodeTypeName,
                $preview,
            );
        }
        return $options;
    }

    /**
     * Resolve a small preview thumbnail URI for a node's image property.
     *
     * The property is expected to hold a Neos.Media asset (e.g. an ImageInterface).
     * If the property is missing, empty, not an asset, or the thumbnail cannot be
     * generated, null is returned so the editor simply renders without a preview.
     */
    private function resolvePreviewUri(Node $node, string $propertyName, int $thumbnailWidth): ?string
    {
        if (!$node->properties->offsetExists($propertyName)) {
            return null;
        }

        try {
            $asset = $this->resolveAssetFromPropertyValue($node->properties->offsetGet($propertyName));
        } catch (\Throwable) {
            return null;
        }

        if ($asset === null) {
            return null;
        }

        try {
            $configuration = new ThumbnailConfiguration(
                width: null,
                maximumWidth: $thumbnailWidth,
                height: null,
                maximumHeight: null,
                allowCropping: false,
                allowUpScaling: false,
                async: false,
            );
            $thumbnailData = $this->assetService->getThumbnailUriAndSizeForAsset($asset, $configuration);
        } catch (\Throwable) {
            return null;
        }

        return is_array($thumbnailData) && isset($thumbnailData['src']) && is_string($thumbnailData['src'])
            ? $thumbnailData['src']
            : null;
    }

    /**
     * Resolve a Neos.Media asset from a node property value.
     *
     * Neos 9 deserializes asset-typed properties to AssetInterface instances via
     * the property collection, but we also handle the raw serialized forms
     * (objects with __identity and `asset://` URI strings) defensively.
     */
    private function resolveAssetFromPropertyValue(mixed $value): ?AssetInterface
    {
        if ($value instanceof AssetInterface) {
            return $value;
        }

        if (is_array($value) && isset($value['__identity']) && is_string($value['__identity'])) {
            $asset = $this->assetRepository->findByIdentifier($value['__identity']);
            return $asset instanceof AssetInterface ? $asset : null;
        }

        if (is_string($value) && preg_match('/^asset:\/\/(?<assetId>[\w-]+)/i', $value, $matches)) {
            $asset = $this->assetRepository->findByIdentifier($matches['assetId']);
            return $asset instanceof AssetInterface ? $asset : null;
        }

        return null;
    }
}
