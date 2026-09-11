# AMAN v1.4 integration

V1.4 is the team-selected model for the hackathon prototype. It is trained on
synthetic simulation and is not a validated public-safety system. Preserve the
existing backend rule path as an independent fallback and keep older model
artifacts available for rollback; do not combine their thresholds or policies
with v1.4.

## Authoritative runtime

Use `aman_inference.adapter_v14.StatefulPredictor(directory)` from this directory.
It invokes the same `AlertState.update` used by evaluation replay. Load trained
parameters from `alert_policy.json`, whose hash is recorded in `frozen.json`.
The earlier policy-only experiment uses `frozen_policy.json`; it is a different
experiment and must not be interchanged with this package.

Requests contain `venueId`, `eventId`, `zoneId`, `timestampSeconds`, and `features`.
Supply exactly the 55 entries in `feature_list.json`, in the exported order.
Identifiers are state keys, never classifier inputs. Do not silently fill missing
evidence with zero.

Construct original features using `features.build_causal_features` and the frozen
observation contract; apply `features_v14.augment` to the full, ordered observation
timeline before removing censored rows. Runtime history must include missing-report
ticks at the five-second cadence. The augmentation uses only current/past density,
thresholds, coverage, uncertainty, and neighbor observations. Four samples span
15 seconds and seven span 30 seconds. Rising/recovery counts count positive/negative
changes within the window; they are not consecutive streak lengths. No future
truth, scenario IDs, labels, or seeds may enter the feature map. Preserve enough
history for the original and added 30-second features.

## State and quality behavior

Use one state instance per venue/event/zone and process timestamps strictly in
order. Reject duplicate or out-of-order samples. A gap other than five seconds
clears temporal evidence. The host must preserve state atomically across service
restarts or explicitly record a reset; this implementation does not provide a
database. Do not reuse state across model or policy versions.

The high threshold triggers immediately, including during cooldown. Otherwise,
K of the last N readings above the lower threshold triggers after cooldown. Release
requires the configured number of consecutive readings below the release threshold
and the minimum duration. All thresholds and durations are frozen, not UI-tunable.

Poor quality or complete outage clears ML evidence even during minimum duration.
Zero coverage yields `outage`; other failures yield `insufficient_data`. No stale
probability is carried forward. With adequate evidence, already-critical observed
density yields `currently_critical`, separate from early warnings. ML quality
abstention must not disable any independently justified existing backend rule.

The Laravel CrowdMonitor currently calculates density risk/hysteresis and journals
rule incidents, but does not consume this adapter. A backend integration must
journal warning transitions with observation/model/policy IDs, preserve idempotency,
and test notification delivery end to end. Python adapter/replay parity is tested;
Laravel integration, durable state, and delivered alerts are not claimed complete.

## Interpretation

Stateless `RiskPredictor.predict` exposes classifier scores and density quantiles;
its single-threshold decision is not the deployed v1.4 alert policy. Use the
stateful adapter. Top drivers explain raw model-score contributions, not causal
effects or calibrated-probability contributions. ETA remains a linear-trend
heuristic, not a trained ETA.

Event recall credits only warning starts 5-30 seconds before crossing, once per
event. The legacy any-active-tick metric is separately labelled. Scored exposure
is noncritical, label-available, unconfounded zone time, excluding each last tick
and including outage time. Observed-critical states are not early-warning delivery.
The p90-p50 spread is not a 90% prediction interval. Review coverage, pinball loss,
spread, and subgroup behavior together. The team manually selected this version
for the hackathon prototype; that is not a production-safety promotion or claim.
