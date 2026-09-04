<?php

return [
    // Demo ingestion is deliberately off until explicitly enabled by a developer.
    'demo_enabled' => (bool) env('AMAN_DEMO_ENABLED', false),
    'observation_max_age_seconds' => 120,
    'signal_max_age_seconds' => 120,
    'future_tolerance_seconds' => 5,
    'recovery_fraction' => 0.8,
    'resolution_stable_seconds' => 15,
    'max_candidates' => 50,
    'max_location_accuracy_meters' => 100,
];
