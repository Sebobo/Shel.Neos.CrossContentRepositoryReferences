# Cross Content Repository References for Neos CMS

[![Latest Stable Version](https://poser.pugx.org/shel/neos-cross-contentrepository-references/v/stable)](https://packagist.org/packages/shel/neos-cross-contentrepository-references)
[![Total Downloads](https://poser.pugx.org/shel/neos-cross-contentrepository-references/downloads)](https://packagist.org/packages/shel/neos-cross-contentrepository-references)
[![License](https://poser.pugx.org/shel/neos-cross-contentrepository-references/license)](https://packagist.org/packages/shel/neos-cross-contentrepository-references)

This package for [NeosCMS](https://www.neos.io) makes it possible to reference
nodes from **any** content repository via the inspector - not only from the
content repository the currently edited node belongs to.

Neos' built-in `reference` and `references` property types are confined to the
content repository of the edited node. When a project uses multiple content
repositories (e.g. a central "content hub" plus per-site repositories), this
package fills the gap by providing a datasource-driven editor and a Fusion
helper to resolve the selected nodes again in the frontend.

## Features

* `ReferencesDataSource` (`cross-content-repository-references`) — a Neos
  datasource that lists nodes from an arbitrary content repository for use in
  the inspector `SelectBoxEditor`.
* The `startingPoint` argument includes the content repository id using the
  pattern `/<contentRepositoryId>/<RootNodeType>/<siteName>`, e.g.
  `/hub/<Neos.Neos:Sites>/example-site`.
* Selected nodes are stored as serialized `CrossContentRepositoryReference`
  JSON strings — one string for a single reference, an array of strings for
  multiple references. The serialized form contains only the content repository
  id and node aggregate id; the workspace and dimension space point are
  resolved from the rendering context (the context node) when the reference is
  loaded in Fusion.
* `NodeHelper` Eel helper (`Shel.Neos.CrossContentRepositoryReferences.Node`)
  to resolve a node (or a list of nodes) from a serialized
  `CrossContentRepositoryReference` in Fusion, using a context node for the
  workspace and dimension.

## Installation

Run this in your site package:

    composer require --no-update shel/neos-cross-contentrepository-references

Then run `composer update` in your project root.

## Usage

### Configure a property

The package ships 2 node type presets `crossContentRepositoryReference` and `crossContentRepositoryReferences`. You can use them to configure a property
of any node type:

```yaml
'Your.Package:NodeType':
  properties:
    myCrossReference:
      options:
        preset: 'crossContentRepositoryReference'
      ui:
        label: 'My reference'
        inspector:
          editorOptions:
            placeholder: 'Select a node'
            dataSourceAdditionalData:
              startingPoint: '/default/<Neos.Neos:Sites>/neos-demo'
              nodeTypes: ['Neos.Neos:Document']
```

### `startingPoint` format

The `startingPoint` is **not** relative to the currently edited content
repository as in Neos' default behaviour. Instead it contains the content
repository id as its first segment:

```
/<contentRepositoryId>/<RootNodeType>/<siteName>
```

Examples:

* `/default/<Neos.Neos:Sites>/my-site` — the `my-site` site in the `default` CR
* `/hub/<Neos.Neos:Sites>/content-hub` — the `content-hub` site in the `hub` CR

### Available `dataSourceAdditionalData` options

| Option            | Required | Default                             | Description                                               |
|-------------------|----------|-------------------------------------|-----------------------------------------------------------|
| `startingPoint`   | yes      | -                                   | `/<crId>/<RootNodeType>/<siteName>`                       |
| `nodeTypes`       | no       | `Neos.Neos:Document`                | Node type names, e.g. [`Neos.Neos:Document`, Foo.Bar:Baz] |
| `dimensionValues` | no       | edited node's dimension space point | JSON object, e.g. `{"language":"de"}`                     |
| `searchTerm`      | no       | -                                   | Fulltext search term to pre-filter results                |

### Resolving selected nodes in Fusion

Selected values are stored as serialized `CrossContentRepositoryReference`
JSON strings (containing only `contentRepositoryId` and `nodeAggregateId`).
Resolve them in Fusion via the registered Eel helper
`Shel.Neos.CrossContentRepositoryReferences.Node`, passing the context node
(whose workspace and dimension space point are used to load the referenced
node) and the serialized reference:

```fusion
prototype(Your.Package:NodeType) {
    # Single reference (property "crossReference" holds a JSON string)
    referenceNode = ${Shel.Neos.CrossContentRepositoryReferences.node(node, node.properties.crossReference)}

    # Multiple references (property "crossReferences" holds an array of JSON strings)
    referenceNodes = ${Shel.Neos.CrossContentRepositoryReferences.nodes(node, node.properties.crossReferences)}

    # Render the referenced nodes
    renderedReferences = Neos.Fusion:Loop {
        items = ${this.referenceNodes}
        itemName = 'referencedNode'
        itemRenderer = Neos.Neos:ContentCase {
            nodePath = '/referencedNode'
        }
    }

    @cache {
        mode = 'cached'
        entryTags {
            # Flush the cache for this node when the referenced nodes change
            references = ${Neos.Caching.nodeTag(Shel.Neos.CrossContentRepositoryReferences.nodes(node, node.properties.crossReferences))}
        }
    }
}
```

The helper returns `Neos\ContentRepository\Core\Projection\ContentGraph\Node`
instances (or `null` / a filtered array if a node could not be resolved), so
you can use them like any other node - including `q(node)` FlowQuery
operations (note: FlowQuery operates within the referenced node's content
repository).

## How it works

1. `ReferencesDataSource::getData()` parses `startingPoint` into a
   `ContentRepositoryId` and an `AbsoluteNodePath`, fetches a subgraph for the
   resolved content repository, workspace and dimension space point, and finds
   the start node via `findNodeByAbsolutePath()`.
2. It lists descendants using
   `ContentSubgraphInterface::findDescendantNodes()` with a `NodeTypeCriteria`
   filter and an optional `SearchTerm`.
3. Each result is mapped to `{label, value, nodeType}` where `value` is
   `CrossContentRepositoryReference::fromNode($node)->toJson()` — containing
   only the content repository id and node aggregate id. This is what gets
   stored on the property.
4. In Fusion, `NodeHelper::node()` deserializes the
   `CrossContentRepositoryReference`, fetches the matching content repository
   and a subgraph using the **context node's** workspace and dimension space
   point, and resolves the node via `findNodeById()`.

## Contributions

Contributions are very welcome! Please create detailed issues and PRs.

**If you use this package and want to support or speed up its development,
[get in touch with me](mailto:me@helzle.it).**

## License

See [License](./LICENSE.txt)
