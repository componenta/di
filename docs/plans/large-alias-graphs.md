# Cold construction of large alias graphs

[Русская версия](large-alias-graphs.ru.md)

**Status, 2026-11-20:** preserved conditional performance proposal. No graph rewrite, alias limit or new public contract is selected. Proceed only when a real consumer profile shows material startup cost in alias construction. This is separate from missing-attribute polling in [B2](attribute-availability-performance.md) and from already corrected alias-family invalidation in [definition ownership](definition-owner-lifecycle.md).

## 1. Problem and historical evidence

The fourth full re-audit identified insertion-order-sensitive cold construction: validating each inserted alias can repeatedly walk the target chain already assembled. For a chain supplied target-near-first, the total traversal can be quadratic in chain length. Successful resolution has its own path compression; a cold construction observation is not a warm-lookup result. The fifth audit retained a separate optional whole-graph validation proposal.

The original records remain available at immutable Git paths:

| Record | Source identity and scope |
|---|---|
| [Round 4, §7](https://github.com/componenta/di/blob/80cb69a59f001a7d9c49af6e254ff3f58ffa8504/docs/audit/2026-11-15-di6-full-reaudit-round4.md#7-performance-evidence-and-limits) | Reports the bounded synthetic alias screening and its limits. |
| [Round 4 core proof](https://github.com/componenta/di/blob/80cb69a59f001a7d9c49af6e254ff3f58ffa8504/docs/audit/evidence/2026-11-15-round4/core-proof.json), `bounded_alias_benchmark` and `embedded_alias_probe` | The proof records baseline `897f8b10ca6537e7b2f96d20b2e00ed52571ff6f`; selected `AliasResolver.php` SHA-256 `2f7f0713f51a60761332ed3b3555f91c783b0a4239075e6de2ee650e885a558c`; probe SHA-256 `49b97f6970a0d52f52c3a1c86aa7b31223dd1eb021b0dde1969b89e4a3aeceb8`. It embeds the source and all six groups of three samples. |
| [Round 5, §6, “Large cold alias graphs”](https://github.com/componenta/di/blob/80cb69a59f001a7d9c49af6e254ff3f58ffa8504/docs/audit/2026-11-16-di6-full-reaudit-round5.md#large-cold-alias-graphs--a-separate-optional-optimization) | Reviewed implementation `42b62afd9c6a57caa0db3916585e823ab6ebaafa`; records the proposed O(V+E) validation target and compatibility requirements. It explicitly ran no new alias benchmark. |

The current source reference for any subsequent implementation is [`AliasResolver.php` at `43c4641`](https://github.com/componenta/di/blob/43c464187f80c95802fc36191350ae68b70a469e/src/Internal/AliasResolver.php), SHA-256 `ee46bed4003306fcf22281a4f513abbdb6a63b8f4fea57a9d0afe55044e9dd93` (Git blob `b61947c0fc390200a92e28d79953fb7be4ab2d5d`). This source identity does not turn the old timing into a current-source measurement.

The screening ran on one local PHP 8.4.23 runtime. Each timed interval included construction **and one final canonical resolution**, with three observations per size/order. The same final chain was supplied in opposite insertion orders:

The graph is `alias.0 → target`, then `alias.i → alias.(i - 1)` for each later ID. The forward case inserts `alias.0` first; the reverse case reverses the input array while keeping exactly the same edges.

| Aliases | Target-near-first median | Reverse-order median |
|---|---:|---:|
| 100 | 1.190491 ms | 0.122499 ms |
| 1,000 | 108.393763 ms | 1.209216 ms |
| 3,000 | 851.768244 ms | 3.605627 ms |

These are historical screening values, not a before/after implementation comparison or current-HEAD measurements. The fixture is an internal primitive with unusually deep chains. Three samples, one runtime and possible concurrent workspace load do not establish a general latency target, ordinary container-build cost, application TTFB, or a language-version ranking. The November 20 inventory reread the report and embedded evidence; it did not execute the probe or perform a fresh source audit.

## 2. Why this is a separate design decision

The intended benefit is lower startup work for a demonstrated large alias configuration. Warm shared retrieval has no established gain from this proposal. Most applications may have small or shallow graphs; adding an algorithm and temporary graph state without a measured need would impose maintenance and memory costs without a demonstrated user benefit.

Changing the validation order can also change which error is observed first. A faster traversal must preserve the selected rules for invalid IDs, self-reference and cycles, plus deterministic cycle diagnostics. Merely recognizing that a graph contains a cycle is insufficient if consumers depend on the documented diagnostic or rejection ordering.

Keep ID admission routes distinct: a numeric-looking string accepted by a runtime string-argument API is not the same input as an integer array key created by PHP key coercion in the constructor map. The current constructor rejects integer alias keys. The proposal must preserve these boundaries rather than broadly declaring every numeric key admissible.

Initial bulk validation and later mutation have different publication boundaries. `AliasResolver::set()`, reached through `Container::alias()`, must not publish a failed candidate or invalidate a previously valid cache. Successful mutation still needs the existing descendant/deferred invalidation and publication/retirement rules. A constructor optimization must not become an accidental rewrite of those guarantees.

## 3. Options and recommendation

| Option | Potential benefit | Cost and decision |
|---|---|---|
| Retain current construction and resolution | No compatibility change; existing warm path remains available. | Recommended without application evidence of material cold cost. |
| Whole-graph initial validation with visited/color state | Target O(V+E) validation for a graph with V IDs and E alias edges; avoids repeated complete traversal of already checked chains. | Requires a deliberate diagnostic order, bounded temporary memory and separate treatment of public mutation. Conditional candidate. |
| Reorder the application's own configuration | May reduce this particular historical worst case. | Must preserve application configuration semantics; it is not a transparent library guarantee or a substitute for correctness checks. Assess only for a named consumer. |
| Add an arbitrary maximum chain length or reject large graphs | Bounds one synthetic input shape. | Changes admissible configuration without an established requirement. Not selected. |
| Rewrite warm caches or introduce a new service-graph layer | No established benefit from this cold-construction evidence. | Expands scope and risks requested-ID, identity and invalidation semantics. Not justified by this proposal. |

**Recommendation:** retain the current implementation until a real profile establishes need. If that gate passes, investigate one bulk-validation candidate inside the existing alias owner. Do not select a graph abstraction, persistent compiler or arbitrary size limit merely because the synthetic worst case exists.

## 4. Conditional implementation plan

1. **Establish the consumer workload.** Record graph size, depth, branching, insertion order, build frequency and alias-construction share of total startup. Preserve a representative configuration or anonymized structural fixture. Distinguish one-time startup from per-request rebuilding.
2. **Measure the phases separately.** Capture constructor-only, first resolution, repeated compressed resolution and public container construction under the same runtime and environment. Retain the historical combined interval only as a historical comparison, not as the new constructor baseline. Include small ordinary graphs as controls.
3. **Write the compatibility oracle.** Fix the required precedence for malformed IDs, empty IDs, self-reference and multiple cycles. Define deterministic cycle-chain selection for each insertion order. Preserve opaque and numeric-string IDs where currently accepted, terminal non-alias IDs, aliases sharing a terminal, and requested-ID delegator semantics. A deliberately changed diagnostic contract needs an explicit migration decision.
4. **Prototype one initial-validation algorithm.** Use traversal state to avoid repeating completed paths; aim for O(V+E) visits and bounded graph-sized working memory. State how long chains avoid unbounded call-stack recursion. Publish the completed initial alias map only after all required checks succeed.
5. **Keep runtime mutation transactional.** Do not silently reuse bulk validation where it changes `set()` failure state, cache ownership or callback order. Failed public mutation must preserve the old map and valid identities; successful mutation must preserve descendant/deferred invalidation and retirement behavior. Reuse existing owners rather than creating a second authority.
6. **Qualify behavior before timing.** Exercise the matrix below through the relevant public APIs, with direct alias controls only where needed to distinguish construction from resolution. Verify error, identity and recovery assertions without private-cache layout expectations.
7. **Measure the selected candidate.** Use bounded serial paired runs with exact source origins, all samples, unchanged dependencies and explicit OPcache/runtime configuration. Measure time and memory separately, including large failure cases and small common configurations. Choose a cost budget from the consumer's startup need; do not infer one from a universal percentage.
8. **Publish only if both gates pass.** The compatibility oracle must hold, and the target application workload must improve enough to justify added state/complexity. Otherwise retain the baseline and record why the candidate was rejected.

## 5. Acceptance matrix

| Area | Required evidence |
|---|---|
| Successful graphs | Empty map, single alias, chains in both insertion orders, shallow and deep branches, many aliases sharing a terminal, disconnected components and terminal IDs without definitions. |
| IDs | Current accepted opaque/numeric-string forms and every currently rejected form; no accidental array-key coercion or new length limit. |
| Failure order | Empty/self-reference cases, cycles of different lengths, multiple independent cycles, malformed entries before/after cycles; selected error ownership and deterministic chain. |
| Resolution | Same canonical result before and after path compression, unaffected shared identity and requested-ID delegator pipeline. |
| Runtime mutation | A rejected `Container::alias()` leaves prior behavior intact; accepted replacement invalidates affected descendants/deferred consumers while preserving unaffected identities. |
| Reentry and lifetime | Existing nested mutation, suspended attempt and retirement controls remain valid where alias changes participate in those contracts. No persistent traversal scratch state retains user service graphs. |
| Complexity | Instrumented graph visits establish the intended scaling independently of noisy wall-clock measurements. Long chains do not introduce an unbounded recursive PHP stack. |
| Performance | Constructor, cold resolution, warm resolution and complete build reported separately; small graphs, representative large graphs and rejection paths included with time/memory limits. |

This report preserves the earlier architecture proposal; it does not make the optimization mandatory, claim a new correctness defect, or justify a warm-lookup performance promise.
