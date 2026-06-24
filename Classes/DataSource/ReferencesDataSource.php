<?php

declare(strict_types=1);

namespace Shel\Neos\CrossContentRepositoryReferences\DataSource;

use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\NodeType\NodeTypeNames;
use Neos\ContentRepository\Core\Projection\ContentGraph\AbsoluteNodePath;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindClosestNodeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindDescendantNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\NodeType\NodeTypeCriteria;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\Nodes;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Model\ThumbnailConfiguration;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Media\Domain\Service\AssetService;
use Neos\Media\Domain\Service\ThumbnailService;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\NodeLabel\NodeLabelGeneratorInterface;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;
use Neos\Neos\FrontendRouting\NodeUriBuilder;
use Neos\Neos\FrontendRouting\NodeUriBuilderFactory;
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

    #[Flow\Inject]
    protected NodeUriBuilderFactory $nodeUriBuilderFactory;

    #[Flow\Inject]
    protected SiteRepository $siteRepository;

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

        // Build a preview URI builder for the current action request so we can
        // produce clickable frontend URLs for each option.
        $nodeUriBuilder = $this->nodeUriBuilderFactory->forActionRequest(
            $this->controllerContext->getRequest(),
        );

        // Determine whether the referenced nodes live in a different content
        // repository. If so, build a backendUri base so the frontend can do a
        // full page reload to the correct domain instead of just updating the
        // content canvas iframe (which would leave the UI shell on the wrong CR).
        $isCrossCr = !$node->contentRepositoryId->equals($contentRepositoryId);
        $backendUriBase = $isCrossCr ? $this->buildBackendUriBase($contentRepositoryId, $subgraph, $startingNode) : null;

        return $this->buildOptions($descendants, $nodeUriBuilder, $imageProperty, $thumbnailWidth, $backendUriBase);
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
     * @param NodeUriBuilder $nodeUriBuilder
     * @param string|null $imageProperty Name of a node property holding an image/asset to render as a preview thumbnail
     * @param int $thumbnailWidth Maximum width of the generated preview thumbnail
     * @param string|null $backendUriBase Base backend URL (without node param) for cross-CR references, or null for same-CR
     * @return list<ReferenceOption>
     */
    private function buildOptions(Nodes $nodes, NodeUriBuilder $nodeUriBuilder, ?string $imageProperty, int $thumbnailWidth, ?string $backendUriBase): array
    {
        $options = [];
        foreach ($nodes as $descendant) {
            $preview = $imageProperty !== null ? $this->resolvePreviewUri($descendant, $imageProperty, $thumbnailWidth) : null;
            $uri = $this->resolveNodeUri($descendant, $nodeUriBuilder);
            $backendUri = $backendUriBase !== null
                ? $backendUriBase . '?node=' . urlencode(NodeAddress::fromNode($descendant)->toJson())
                : null;
            $options[] = new ReferenceOption(
                $this->nodeLabelGenerator->getLabel($descendant),
                CrossContentRepositoryReference::fromNode($descendant),
                $descendant->nodeTypeName,
                $preview,
                $uri,
                $backendUri,
            );
        }
        return $options;
    }

    /**
     * Build the base backend URL for cross-CR references.
     *
     * Resolves the target site's primary domain and constructs the Neos backend
     * URL (BackendController::indexAction) on that domain. Each individual option
     * will append `?node=<NodeAddress JSON>` to this base.
     *
     * @return string Backend URL (without node query parameter), e.g. `https://other-cr-site.tld/neos/content`
     */
    private function buildBackendUriBase(ContentRepositoryId $targetCrId, ContentSubgraphInterface $subgraph, Node $startingNode): string
    {
        // Find the site node belonging to the target content repository so we
        // can look up its primary domain.
        $site = $this->resolveSiteForContentRepository($subgraph, $startingNode);
        $primaryDomain = $site?->getPrimaryDomain();

        // Build the backend route URL (e.g. /neos/content) using the UriBuilder
        // of the current controller context.
        $uriBuilder = $this->controllerContext->getUriBuilder();
        $uriBuilder->reset();
        $uriBuilder->setFormat('html');
        $uriBuilder->setCreateAbsoluteUri(true);
        $backendUrl = $uriBuilder->uriFor('index', [], 'Backend', 'Neos.Neos.Ui');

        // If the target site has a domain and it differs from the current
        // request's host, replace the host (and scheme / port if needed).
        if ($primaryDomain !== null) {
            $currentUri = new \GuzzleHttp\Psr7\Uri($backendUrl);
            $targetHost = $primaryDomain->getHostname();
            if ($targetHost !== $currentUri->getHost()) {
                $backendUrl = (string)$currentUri
                    ->withHost($targetHost)
                    ->withScheme($primaryDomain->getScheme() ?: $currentUri->getScheme())
                    ->withPort($primaryDomain->getPort() ?: $currentUri->getPort());
            }
        }

        return $backendUrl;
    }

    /**
     * Resolve the Neos Site entity for the site containing the given starting node.
     *
     * Finds the closest site ancestor of the starting node and looks up the
     * Site entity by its node name. Returns null if no site can be resolved.
     */
    private function resolveSiteForContentRepository(ContentSubgraphInterface $subgraph, Node $startingNode): ?Site
    {
        $siteNode = $subgraph->findClosestNode(
            $startingNode->aggregateId,
            FindClosestNodeFilter::create(nodeTypes: NodeTypeNameFactory::NAME_SITE),
        );
        if ($siteNode === null || $siteNode->name === null) {
            return null;
        }
        return $this->siteRepository->findOneByNodeName((string)$siteNode->name);
    }

    /**
     * Build a frontend preview URI for a node.
     *
     * Uses the full {@see NodeAddress} (including content repository id,
     * workspace, dimension space point) so the preview URL correctly navigates
     * to the node even when it lives in a different content repository.
     *
     * URI generation is wrapped in a try/catch — if routing fails (unlikely)
     * the option simply renders without a clickable link.
     */
    private function resolveNodeUri(Node $node, NodeUriBuilder $nodeUriBuilder): ?string
    {
        try {
            $nodeAddress = NodeAddress::fromNode($node);
            return (string)$nodeUriBuilder->previewUriFor($nodeAddress);
        } catch (\Throwable) {
            return null;
        }
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
