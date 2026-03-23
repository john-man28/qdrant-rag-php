# Detailed Markdown Plan: Port `qdrant-client` Python SDK to PHP

## Summary
This plan turns the Python `qdrant-client` `1.17.1` SDK into a PHP package we can implement incrementally, phase by phase, while preserving a path to full server-side parity.

The Python SDK is split into:
- A high-level client facade
- Generated REST API modules
- Generated gRPC stubs
- A REST/gRPC conversion layer
- Handwritten helper logic for uploads, retries, batching, migration, and compatibility checks

The PHP port should follow the same architecture, but with one deliberate simplification:
- Scope includes full **remote/server-facing SDK parity**
- Scope excludes `QdrantLocal` and FastEmbed/local inference

The target output is a Composer package with a stable sync API first, then optional gRPC, then optional async.

## Target Package Shape
Proposed package structure:

```text
src/
  QdrantClient.php
  Config/
  Transport/
    Rest/
    Grpc/
  Models/
  Api/
  Conversion/
  Exceptions/
  Support/
  Upload/
  Migration/
codegen/
  openapi/
  proto/
  scripts/
tests/
  Unit/
  Integration/
  Parity/
docs/
```

Proposed public namespace:

```php
Qdrant\QdrantClient
Qdrant\Models\*
Qdrant\Exceptions\*
```

## Versioning Baseline
- Upstream parity baseline: Python `qdrant-client` `1.17.1`
- Server contract sources:
  - Qdrant OpenAPI schema
  - Qdrant protobuf definitions
- PHP baseline: `8.3+`
- Packaging: Composer
- First stable release target: sync REST-first client with complete public model layer and a defined path to gRPC parity

## Architectural Decisions
- Canonical public model layer in PHP should be REST DTOs and enums, mirroring Python’s practical public surface.
- gRPC should be an optional transport behind the same high-level client methods.
- Generated code and handwritten code must stay clearly separated.
- The high-level client should expose ergonomic helpers and hide transport-specific complexity.
- Feature development should follow this order:
  1. Models and REST transport
  2. High-level sync client
  3. Upload/migration helpers
  4. gRPC transport parity
  5. Async layer

## Phase 0: Discovery Freeze and Parity Inventory
### Goal
Lock the upstream SDK surface so implementation does not drift while we port.

### Work
- Record the exact upstream package version: `1.17.1`
- Inventory the public sync API from Python
- Inventory the async API from Python
- Inventory REST API groups
- Inventory exported model types
- Inventory handwritten helper behavior not covered by pure schema generation
- Classify features into:
  - REST-generated
  - gRPC-generated
  - handwritten client facade
  - handwritten helpers
  - out of scope

### Known baseline from review
- Roughly `66` public client methods
- Roughly `460` exported REST model symbols
- Main handwritten areas:
  - `QdrantClient`
  - `QdrantRemote`
  - `ApiClient`
  - conversions
  - upload helpers
  - migration
  - compatibility checks

### Deliverables
- Method inventory document
- Model inventory document
- Feature classification matrix
- Parity checklist that can be used as an implementation tracker

### Acceptance Criteria
- Every public Python client method is accounted for
- Every method is labeled with implementation strategy
- Out-of-scope features are explicitly listed so they do not silently get dropped

## Phase 1: Package Skeleton and Tooling
### Goal
Create the PHP package foundation and code generation workflow.

### Work
- Initialize Composer package
- Define PSR-4 autoloading
- Add coding standards and static analysis
- Add PHPUnit or Pest
- Add codegen scripts for:
  - OpenAPI -> PHP DTOs and endpoint wrappers
  - `.proto` -> PHP protobuf/grpc classes
- Separate generated output from handwritten source
- Add CI workflow for:
  - lint
  - static analysis
  - unit tests
  - codegen drift check

### Proposed package dependencies
- PSR-7 / PSR-17 / PSR-18 interfaces
- Default HTTP client implementation, likely Guzzle
- Optional gRPC/protobuf runtime deps
- PHPUnit or Pest
- PHPStan
- PHP CS Fixer or Pint-equivalent for PHP

### Deliverables
- `composer.json`
- package skeleton
- codegen scripts
- CI config
- baseline docs on how to regenerate code

### Acceptance Criteria
- Fresh clone can install dependencies and run tests
- Generated code can be reproduced from source specs
- CI fails if generated code is stale

## Phase 2: REST Model Layer
### Goal
Generate and stabilize the full REST DTO and enum surface.

### Work
- Generate all REST request/response models from Qdrant OpenAPI
- Convert OpenAPI enums to PHP backed enums where practical
- Standardize serialization and hydration:
  - `fromArray(array $data): self`
  - `toArray(): array`
- Preserve nullable vs required field semantics
- Preserve discriminated unions and variant types
- Add normalization helpers for dates, UUIDs, numeric arrays, and payload maps
- Ensure model names are predictable and stable

### Important design rule
- The public PHP model layer should be the primary type system users interact with.
- Even when gRPC is used internally, the public high-level client should continue speaking these same PHP models.

### Deliverables
- `Qdrant\Models\*`
- enum set
- serializer/hydrator conventions
- model-level tests

### Acceptance Criteria
- Generated models cover all server-facing REST types
- Round-trip serialization tests pass
- Required/optional field behavior matches API contract
- Union/variant models are usable without transport-specific knowledge

## Phase 3: REST Transport Core
### Goal
Implement a robust sync REST transport with typed error handling.

### Work
- Build HTTP client wrapper using PSR-18
- Implement request building:
  - base URL
  - prefix path handling
  - headers
  - auth
  - timeout
  - query params
  - JSON body
- Implement response handling:
  - decode JSON
  - hydrate typed DTOs
  - map errors to SDK exceptions
- Implement retry-after parsing for `429`
- Add configurable middleware/hooks
- Set and document a PHP SDK user-agent string

### Python behavior to mirror
- `api-key` header handling
- optional auth token provider behavior
- user-agent override rules
- prefix-aware URL joining
- request timeout semantics
- `429 Retry-After` surfaced as typed rate-limit exception

### Deliverables
- `Transport\Rest\ApiClient`
- request/response abstraction
- exception mapping
- middleware support

### Acceptance Criteria
- REST transport can hit a live Qdrant instance
- Success responses hydrate correctly
- `4xx/5xx/429` errors become typed SDK exceptions
- URL prefix and auth behavior match expected server behavior

## Phase 4: Generated REST Endpoint APIs
### Goal
Expose REST endpoints as organized low-level API groups.

### Work
Generate or implement endpoint groups equivalent to Python:
- `AliasesApi`
- `BetaApi`
- `CollectionsApi`
- `DistributedApi`
- `IndexesApi`
- `PointsApi`
- `SearchApi`
- `ServiceApi`
- `SnapshotsApi`

Each group should:
- Accept typed DTOs
- Return typed DTOs
- Hide manual route construction from high-level callers

### Deliverables
- `Api\Rest\*Api` classes
- endpoint integration tests

### Acceptance Criteria
- Each REST route used by the Python SDK is represented
- Grouped APIs can be consumed directly for low-level access
- Generated APIs are stable enough to support the high-level client

## Phase 5: Sync High-Level Client Facade
### Goal
Build the main `QdrantClient` sync API that mirrors the Python remote client.

### Work
Implement high-level client construction with:
- `url`
- `host`
- `port`
- `grpcPort`
- `preferGrpc`
- `https`
- `apiKey`
- `prefix`
- `timeout`
- `headers`
- `checkCompatibility`
- `poolSize`

Implement high-level methods, grouped by area.

### Collection management
- `getCollections`
- `getCollection`
- `collectionExists`
- `createCollection`
- `recreateCollection`
- `updateCollection`
- `deleteCollection`

### Point and query operations
- `queryPoints`
- `queryBatchPoints`
- `queryPointsGroups`
- `scroll`
- `retrieve`
- `count`
- `facet`
- `upsert`
- `delete`
- `batchUpdatePoints`

### Payload and vector ops
- `setPayload`
- `overwritePayload`
- `deletePayload`
- `clearPayload`
- `updateVectors`
- `deleteVectors`

### Alias and index ops
- `getAliases`
- `getCollectionAliases`
- `updateCollectionAliases`
- `createPayloadIndex`
- `deletePayloadIndex`

### Snapshot and recovery ops
- `listSnapshots`
- `createSnapshot`
- `deleteSnapshot`
- `listFullSnapshots`
- `createFullSnapshot`
- `deleteFullSnapshot`
- `recoverSnapshot`
- `listShardSnapshots`
- `createShardSnapshot`
- `deleteShardSnapshot`
- `recoverShardSnapshot`

### Cluster and distributed ops
- `createShardKey`
- `deleteShardKey`
- `listShardKeys`
- `collectionClusterInfo`
- `clusterStatus`
- `clusterTelemetry`
- `clusterCollectionUpdate`
- `recoverCurrentPeer`
- `removePeer`

### Migration
- `migrate`

### Deliverables
- `QdrantClient`
- config object or constructor normalization logic
- method coverage tracker

### Acceptance Criteria
- Every in-scope Python sync method has a PHP equivalent
- Methods return PHP DTOs, not raw arrays
- Client can operate entirely through REST with no gRPC dependency

## Phase 6: Handwritten Helper Logic
### Goal
Port the non-generated value-add behavior that makes the SDK more than an OpenAPI wrapper.

### Work
Implement:
- `uploadPoints`
- `uploadCollection`
- batch iteration helpers
- automatic chunking
- retry loops for batch upload failures
- compatibility version check against server
- helper ergonomics for commonly used operations

### Upload behavior to preserve
- Support iterables/generators
- Support batching by configurable batch size
- Support retry count
- Support optional wait flag
- Support shard key selector
- Support update filter
- Support update mode

### Migration behavior
- Scroll source collection in batches
- Create destination collection if needed
- Recreate on collision when requested
- Upsert into destination
- Preserve collection configuration where supported

### Deliverables
- `Upload\*`
- `Migration\*`
- helper tests and integration coverage

### Acceptance Criteria
- Large point uploads work through iterator-based batching
- Retry logic behaves predictably
- Migration can copy collections between two live Qdrant endpoints
- Helper APIs cover the flows used in this repo

## Phase 7: gRPC Foundation
### Goal
Add optional gRPC transport support without changing the public high-level model layer.

### Work
- Generate PHP protobuf classes from Qdrant `.proto`
- Generate PHP gRPC service stubs
- Build `Transport\Grpc\GrpcClient`
- Implement channel configuration:
  - secure/insecure
  - metadata headers
  - auth token support
  - compression
  - timeout
  - pooling where practical
- Add runtime capability checks for missing gRPC/protobuf support

### Important constraint
Local inspection showed this machine currently has:
- `curl`, `json`, `openssl`
- no `grpc` extension
- no `protobuf` extension

So gRPC must remain optional and gracefully unavailable unless the runtime is prepared for it.

### Deliverables
- `Transport\Grpc\*`
- generated protobuf output
- install documentation for gRPC support

### Acceptance Criteria
- gRPC can be enabled in a prepared environment
- Missing extensions fail fast with clear setup guidance
- Public client can switch between REST and gRPC without API changes

## Phase 8: REST <-> gRPC Conversion Layer
### Goal
Allow the high-level client to keep one public type system while using either transport.

### Work
- Build converters from public REST DTOs to protobuf messages
- Build converters from protobuf responses to public REST DTOs
- Handle complex structures:
  - filters
  - payload values
  - range types
  - sparse vectors
  - named vectors
  - query objects
  - UUIDs
  - timestamps
  - point selectors
- Mirror Python’s canonical behavior where REST models stay public and gRPC stays internal

### Deliverables
- `Conversion\RestToGrpc`
- `Conversion\GrpcToRest`
- parity tests against Python behavior

### Acceptance Criteria
- High-level methods can route to gRPC and still return the same PHP DTOs
- Converters correctly handle nested filters and payload structures
- Cross-transport responses normalize to the same PHP shapes

## Phase 9: gRPC High-Level Method Parity
### Goal
Enable `preferGrpc` for methods where the Python SDK supports it.

### Work
- Route eligible high-level operations through gRPC
- Keep REST fallback for methods not covered or not worth routing through gRPC
- Match Python transport preferences and behavior where practical
- Verify parity for core query, CRUD, and collection methods first
- Then expand to snapshots and cluster operations

### Deliverables
- `preferGrpc` support in `QdrantClient`
- transport parity tests
- transport selection docs

### Acceptance Criteria
- Core point and collection methods work over gRPC
- Responses match REST-normalized DTOs
- Users can switch transports without changing call sites

## Phase 10: Async Client
### Goal
Add async support after sync parity is stable.

### Work
- Introduce `AsyncQdrantClient`
- Use Amp or ReactPHP-based strategy, with one chosen standard
- Mirror sync method names and return async primitives
- Start with REST async support
- Add async gRPC only if the runtime story is maintainable

### Deliverables
- `AsyncQdrantClient`
- async transport
- async tests

### Acceptance Criteria
- Async REST methods cover the sync public surface
- Async behavior is documented and testable
- Sync and async APIs remain aligned via coverage checks

## Phase 11: Documentation and Examples
### Goal
Make the SDK easy to adopt and easy to extend.

### Work
Write docs for:
- installation
- basic client creation
- auth and cloud usage
- collection creation
- upsert
- upload batching
- query/search
- retrieve and scroll
- snapshots
- migration
- REST vs gRPC
- async usage
- regeneration/codegen workflow

### Priority examples for this repo’s real usage
- `collectionExists`
- `createCollection`
- `uploadPoints`
- `upsert`
- `queryPoints`
- `retrieve`
- `scroll`

### Deliverables
- README
- docs pages
- migration notes from Python concepts to PHP usage

### Acceptance Criteria
- A PHP user can follow docs and reproduce the current repo’s Qdrant usage
- Contributors can regenerate code and understand where handwritten logic belongs

## Phase 12: Release and Compatibility Policy
### Goal
Ship a maintainable package with explicit compatibility rules.

### Work
- Define package versioning policy
- Publish supported Qdrant server version range
- Publish supported upstream Python baseline or schema baseline
- Add release checklist:
  - regenerate code
  - run unit tests
  - run live integration tests
  - run parity tests
  - update changelog

### Deliverables
- release checklist
- versioning policy
- compatibility matrix

### Acceptance Criteria
- Every release can be reproduced
- Upstream schema drift is visible
- Users know exactly which Qdrant versions are supported

## Detailed Testing Strategy
### Unit tests
- DTO serialization
- enum mapping
- request builders
- exception mapping
- retry-after parsing
- converter correctness
- upload batch splitting
- migration helpers

### Integration tests
Against a live Qdrant container:
- collection CRUD
- point CRUD
- payload operations
- query operations
- aliases
- indexes
- snapshots
- distributed APIs where practical
- migration between two running instances

### Cross-language parity tests
- Run matching Python and PHP operations against the same Qdrant instance
- Normalize responses and compare:
  - collection metadata
  - retrieved records
  - query response shapes
  - payload serialization
  - snapshot metadata where deterministic

### Transport parity tests
- REST and gRPC should produce equivalent normalized outputs for the same input

## Suggested Implementation Order for Actual Work
If we want the most practical phase-by-phase build order, use this sequence:

1. Phase 0: inventory and checklist
2. Phase 1: package skeleton and tooling
3. Phase 2: REST model layer
4. Phase 3: REST transport core
5. Phase 4: generated REST endpoint APIs
6. Phase 5: sync high-level client facade
7. Phase 6: handwritten helpers
8. Phase 11: docs for sync REST-first usage
9. Phase 7: gRPC foundation
10. Phase 8: conversion layer
11. Phase 9: gRPC high-level parity
12. Phase 10: async client
13. Phase 12: release policy hardening

## First Milestone Definition
The first milestone should be a usable sync REST-first SDK that supports:
- package install
- typed models
- low-level REST APIs
- high-level `QdrantClient`
- `createCollection`
- `collectionExists`
- `upsert`
- `uploadPoints`
- `queryPoints`
- `retrieve`
- `scroll`

This milestone is smaller than full parity but is the correct base for the later phases.

## Assumptions
- The port is a new Composer package, not an ad hoc set of PHP scripts inside this repo.
- PHP `8.3+` is acceptable as the minimum supported version.
- Public high-level PHP models should be REST DTO based.
- gRPC is optional and must not block the first working release.
- `QdrantLocal` and FastEmbed/local inference are excluded from scope.
- Parity is measured against Python `qdrant-client` `1.17.1`.

## Definition of Done
The PHP port is done when:
- every in-scope Python sync method has a PHP equivalent
- the public PHP model layer is complete and typed
- REST is fully usable without optional extensions
- gRPC is supported in prepared runtimes
- helper methods for upload and migration behave predictably
- docs cover installation through production usage
- tests verify model, transport, integration, and parity behavior
