from __future__ import annotations

from typing import Any

import numpy as np
import pandas as pd


IDENTIFIER_COLUMNS = [
    "run_id",
    "base_scenario_id",
    "zone_id",
    "timestamp",
    "tick",
    "split",
    "source",
]

MODEL_FEATURE_COLUMNS = [
    "zone_area_m2",
    "warning_threshold",
    "critical_threshold",
    "people_per_device_configured",
    "device_count",
    "estimated_people",
    "estimated_density",
    "neighbor_device_count_sum",
    "neighbor_estimated_density_mean",
    "neighbor_estimated_density_max",
    "venue_device_count",
    "venue_estimated_occupancy",
    "intervention_active",
    "ambiguous_count",
    "stale_count",
    "missing_count",
    "valid_observation_count",
    "active_device_count",
    "coverage_ratio",
    "uncertain_ratio",
    "time_since_last_valid_reading",
    "density_lag_5s",
    "density_lag_15s",
    "density_lag_30s",
    "device_count_lag_5s",
    "device_count_lag_15s",
    "device_count_lag_30s",
    "density_change_5s",
    "density_change_15s",
    "density_change_30s",
    "density_slope_5s",
    "density_slope_15s",
    "density_slope_30s",
    "density_acceleration_5s",
    "density_rolling_mean_15s",
    "density_rolling_max_15s",
    "density_rolling_std_15s",
    "density_rolling_mean_30s",
    "density_rolling_max_30s",
    "density_rolling_std_30s",
    "estimated_inflow_proxy_per_second",
    "estimated_outflow_proxy_per_second",
    "warning_margin",
    "critical_margin",
    "critical_margin_ratio",
    "neighbor_density_change_15s",
    "available_history_seconds",
]

LABEL_COLUMNS = [
    "critical_within_30s",
    "max_density_next_30s",
    "seconds_until_critical",
    "currently_critical",
    "insufficient_quality",
    "insufficient_quality_reason",
    "prediction_eligible",
]

PROHIBITED_MODEL_TOKENS = {
    "future",
    "ground_truth",
    "random_seed",
    "scenario_family",
    "elapsed_seconds",
    "bottleneck",
    "ood_reason",
    "network_congestion",
    "responder",
    "label",
}


def build_causal_features(observations: pd.DataFrame, config: dict[str, Any]) -> pd.DataFrame:
    """Build current-and-past-only zone features."""
    tick_seconds = int(config["tick_seconds"])
    history_seconds = int(config["history_seconds"])
    frame = observations.sort_values(["run_id", "zone_id", "tick"], kind="stable").reset_index(drop=True).copy()
    group_keys = ["run_id", "zone_id"]
    grouped = frame.groupby(group_keys, sort=False, observed=True)
    if frame.duplicated(group_keys + ['tick']).any() or not grouped['tick'].diff().dropna().eq(1).all():
        raise ValueError('Features require unique contiguous run-zone ticks; resample missing telemetry explicitly')

    lag_periods = {seconds: seconds // tick_seconds for seconds in (5, 15, 30)}
    for seconds, periods in lag_periods.items():
        if seconds % tick_seconds != 0:
            raise ValueError(f"Configured tick size does not support the {seconds}-second feature")
        frame[f"density_lag_{seconds}s"] = grouped["estimated_density"].shift(periods)
        frame[f"device_count_lag_{seconds}s"] = grouped["device_count"].shift(periods)
        frame[f"density_change_{seconds}s"] = frame["estimated_density"] - frame[f"density_lag_{seconds}s"]
        frame[f"density_slope_{seconds}s"] = frame[f"density_change_{seconds}s"] / seconds

    slope_grouped = frame.groupby(group_keys, sort=False, observed=True)
    frame["density_acceleration_5s"] = (
        frame["density_slope_5s"] - slope_grouped["density_slope_5s"].shift(1)
    ) / tick_seconds

    for seconds in (15, 30):
        window = seconds // tick_seconds + 1
        rolling = frame.groupby(group_keys, sort=False, observed=True)["estimated_density"].rolling(
            window=window, min_periods=1
        )
        frame[f"density_rolling_mean_{seconds}s"] = rolling.mean().reset_index(level=group_keys, drop=True)
        frame[f"density_rolling_max_{seconds}s"] = rolling.max().reset_index(level=group_keys, drop=True)
        frame[f"density_rolling_std_{seconds}s"] = (
            rolling.std(ddof=0).reset_index(level=group_keys, drop=True).fillna(0.0)
        )

    people_change = frame["estimated_people"] - frame.groupby(group_keys, sort=False, observed=True)[
        "estimated_people"
    ].shift(1)
    frame["estimated_inflow_proxy_per_second"] = people_change.clip(lower=0) / tick_seconds
    frame["estimated_outflow_proxy_per_second"] = (-people_change).clip(lower=0) / tick_seconds
    frame["warning_margin"] = frame["warning_threshold"] - frame["estimated_density"]
    frame["critical_margin"] = frame["critical_threshold"] - frame["estimated_density"]
    frame["critical_margin_ratio"] = frame["critical_margin"] / frame["critical_threshold"]
    frame["neighbor_density_change_15s"] = frame["neighbor_estimated_density_mean"] - frame.groupby(
        group_keys, sort=False, observed=True
    )["neighbor_estimated_density_mean"].shift(3)
    frame["available_history_seconds"] = np.minimum(
        frame.groupby(group_keys, sort=False, observed=True).cumcount() * tick_seconds,
        history_seconds,
    ).astype("int16")
    return frame


def build_labels(
    observations: pd.DataFrame,
    ground_truth: pd.DataFrame,
    config: dict[str, Any],
) -> pd.DataFrame:
    """Build clean future labels and audit flags without exposing latent truth as features."""
    keys = ["run_id", "base_scenario_id", "timestamp", "tick", "zone_id"]
    truth_columns = keys + ["ground_truth_density", "intervention_active"]
    frame = observations.merge(
        ground_truth[truth_columns],
        on=keys,
        how="outer",
        validate="one_to_one",
        indicator=True,
        suffixes=("", "_truth"),
    ).sort_values(["run_id", "zone_id", "tick"], kind="stable").reset_index(drop=True)
    if not frame['_merge'].eq('both').all():
        raise ValueError('Observation/truth keys must match exactly')
    frame = frame.drop(columns='_merge')
    group_keys = ["run_id", "zone_id"]
    grouped = frame.groupby(group_keys, sort=False, observed=True)
    if not grouped['tick'].diff().dropna().eq(1).all() or not grouped['timestamp'].diff().dropna().eq(pd.Timedelta(seconds=config['tick_seconds'])).all():
        raise ValueError('Labels require contiguous correctly timestamped ticks')
    horizon_steps = int(config["forecast_horizon_seconds"] // config["tick_seconds"])
    future_density = pd.concat(
        [grouped["ground_truth_density"].shift(-step).rename(f"future_{step}") for step in range(1, horizon_steps + 1)],
        axis=1,
    )
    future_intervention = pd.concat(
        [grouped["intervention_active_truth"].shift(-step).rename(f"future_intervention_{step}") for step in range(1, horizon_steps + 1)],
        axis=1,
    ).astype("boolean")
    frame["label_available"] = future_density.notna().all(axis=1)
    frame["max_density_next_30s"] = future_density.max(axis=1, skipna=False)
    crossing = future_density.ge(frame["critical_threshold"], axis=0)
    frame["critical_within_30s"] = crossing.any(axis=1).astype("Int8")
    frame.loc[~frame["label_available"], "critical_within_30s"] = pd.NA
    seconds_until = pd.Series(np.nan, index=frame.index, dtype="float64")
    for step in range(1, horizon_steps + 1):
        first_crossing = crossing[f"future_{step}"] & seconds_until.isna()
        seconds_until.loc[first_crossing] = step * int(config["tick_seconds"])
    frame["seconds_until_critical"] = seconds_until.where(frame['label_available'])
    frame["currently_critical"] = (frame["ground_truth_density"] >= frame["critical_threshold"]).astype("int8")
    current_intervention = frame["intervention_active_truth"].astype(bool)
    frame["policy_confounded"] = (~current_intervention) & future_intervention.fillna(False).any(axis=1)

    policy = config["quality_policy"]
    insufficient_history = frame["available_history_seconds"] < int(policy["minimum_history_seconds"])
    low_coverage = frame["coverage_ratio"] < float(policy["minimum_coverage_ratio"])
    excessive_uncertainty = frame["uncertain_ratio"] > float(policy["maximum_uncertain_ratio"])
    stale_reading = frame["time_since_last_valid_reading"] > int(policy["maximum_seconds_since_valid"])
    frame["insufficient_quality"] = (
        insufficient_history | low_coverage | excessive_uncertainty | stale_reading
    ).astype("int8")

    reasons = np.full(len(frame), "sufficient", dtype=object)
    reason_masks = [
        (insufficient_history, "insufficient_history"),
        (low_coverage, "low_coverage"),
        (excessive_uncertainty, "excessive_uncertainty"),
        (stale_reading, "stale_latest_observation"),
    ]
    for mask, reason in reason_masks:
        reasons = np.where(mask & (reasons == "sufficient"), reason, reasons)
    frame["insufficient_quality_reason"] = reasons
    frame["prediction_eligible"] = (
        frame["label_available"]
        & ~frame["policy_confounded"]
        & (frame["currently_critical"] == 0)
        & (frame["insufficient_quality"] == 0)
    ).astype("int8")
    return frame


def build_model_dataset(
    observations: pd.DataFrame,
    ground_truth: pd.DataFrame,
    config: dict[str, Any],
    *, include_timeline: bool = False,
) -> tuple:
    features = build_causal_features(observations, config)
    labelled = build_labels(features, ground_truth, config)
    audit = {
        "rows_before_label_filter": int(len(labelled)),
        "censored_incomplete_horizon_rows": int((~labelled["label_available"]).sum()),
        "censored_future_intervention_rows": int((labelled["label_available"] & labelled["policy_confounded"]).sum()),
    }
    eligible_horizon = labelled["label_available"] & ~labelled["policy_confounded"]
    model = labelled.loc[eligible_horizon, IDENTIFIER_COLUMNS + MODEL_FEATURE_COLUMNS + LABEL_COLUMNS].copy()
    model["critical_within_30s"] = model["critical_within_30s"].astype("int8")
    model = model.sort_values(["run_id", "zone_id", "tick"], kind="stable").reset_index(drop=True)
    audit["model_rows"] = int(len(model))
    audit["prediction_eligible_rows"] = int(model["prediction_eligible"].sum())
    if include_timeline:
        timeline = labelled[IDENTIFIER_COLUMNS + LABEL_COLUMNS + [
            'label_available', 'policy_confounded', 'estimated_density', 'critical_threshold',
            'intervention_active', 'available_history_seconds']].copy()
        # Omitted training rows still need causal inference for complete operational replay.
        extra = labelled.loc[~eligible_horizon, IDENTIFIER_COLUMNS + MODEL_FEATURE_COLUMNS].copy()
        return model, audit, timeline, extra
    return model, audit
