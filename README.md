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
* Selected nodes are stored as serialized `CrossContentRepositoryReference`. 
  The serialized form contains only the content repository
  id and node aggregate id; the workspace and dimension space point are
  resolved from the rendering context (the context node) when the reference is
  loaded in Fusion.
* `ReferencesHelper` Eel helper (`Shel.Neos.CrossContentRepositoryReferences`)
  to resolve a node (or a list of nodes) from a serialized
  `CrossContentRepositoryReference` in Fusion, using a context node for the
  workspace and dimension.
* `referenceNodesAcrossCR` {@see \Shel\Neos\CrossContentRepositoryReferences\FlowQueryOperations\ReferenceNodesAcrossCROperation
  FlowQuery operation} that reads cross-CR references from a node property
  and replaces the FlowQuery context with the resolved nodes:
  `${q(node).referenceNodesAcrossCR("propertyName")}`.

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
prototype(Your.Package:NodeType) < prototype(Neos.Neos:ContentComponent) {
    # Single reference (property "crossReference" holds a JSON string)
    referenceNode = ${Shel.Neos.CrossContentRepositoryReferences.node(node, node.properties.crossReference)}

    # Multiple references (property "crossReferences" holds an array of JSON strings)
    referenceNodes = ${Shel.Neos.CrossContentRepositoryReferences.nodes(node, node.properties.crossReferences)}

    # Render the referenced nodes
    renderer = Neos.Fusion:Loop {
        items = ${props.referenceNodes}
        itemName = 'referencedNode'
        itemRenderer = Neos.Neos:ContentCase {
            node = ${referencedNode}
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

### Using the `referenceNodesAcrossCR` FlowQuery operation

The package also provides a dedicated FlowQuery operation that reads the
cross-CR references from a node property and replaces the context with the
resolved nodes, enabling further chaining:

```fusion
prototype(Your.Package:NodeType) < prototype(Neos.Neos:ContentComponent) {
    # The operation reads the property "crossReferences" from the current node,
    # resolves the stored cross-CR references, and sets the resolved nodes
    # as the FlowQuery context.
    items = ${q(node).referenceNodesAcrossCR('crossReferences')}
    
    # Now iterate over the resolved nodes and render them
    renderer = Neos.Fusion:Loop {
        items = ${props.items}
        itemName = 'referencedNode'
        itemRenderer = Neos.Neos:ContentCase {
            node = ${referencedNode}
        }
    }

    # Further chaining works as usual
    titles = ${q(node).referenceNodesAcrossCR('crossReferences').property('title')}

    @cache {
        mode = 'cached'
        entryTags {
            references = ${Neos.Caching.nodeTag(q(node).referenceNodesAcrossCR('crossReferences'))}
        }
    }
}
```

Single and multiple references are both supported: if the property contains a
single JSON string, one node is resolved; if it contains an array of strings,
all referenced nodes are resolved. Unresolvable entries are silently skipped.

## How it works

The Neos 9 CR uses a separate table for each CR to store references. Neos 8 stored them as properties.
As cross-CR references are not supported in the Neos 9 core yet, this package stores them also as properties. The helpers in this package and the datasource make this mostly invisible to the integrator. But this also means that these references cannot have their own properties (yet) and are not bidirectional.

The package uses the DTO `CrossContentRepositoryReference` to store the
serialized reference. This DTO could be extended in the future with a custom editor UI to also support properties on the reference itself.

The DTO only stores the NodeAggregateId and ContentRepositoryId in the reference. The dimension resolution happens when loading the referenced node and uses the context of the source node to find the best match in the target content repository. Dimensions that don't exist in the context node are ignored. For dimensions that only exist in the target content repository, the default is used.
If dimensions exist in both repositories, the values are matched.

Caching works like with normal nodes, see the example above.

## Contributions

Contributions are very welcome! Please create detailed issues and PRs.

**If you use this package and want to support or speed up its development,
[get in touch with me](mailto:me@helzle.it).**

## License

See [License](./LICENSE.txt)
