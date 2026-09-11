"""CPU portable, feature-allowlisted inference. No labels/LLM explanations."""
from __future__ import annotations

import json
from pathlib import Path

import lightgbm as lgb
import numpy as np
import pandas as pd
from scipy.special import expit

from .bundle import sha256


from .calibration_v14 import calibrate


def quality_reason(features, policy):
    checks = [('available_history_seconds', lambda x:x < policy['minimum_history_seconds'], 'insufficient_history'),
              ('coverage_ratio',lambda x:x < policy['minimum_coverage_ratio'],'low_coverage'),
              ('uncertain_ratio',lambda x:x > policy['maximum_uncertain_ratio'],'excessive_uncertainty'),
              ('time_since_last_valid_reading',lambda x:x > policy['maximum_seconds_since_valid'],'stale_latest_observation')]
    for name, bad, reason in checks:
        if name not in features or features[name] is None or not np.isfinite(features[name]):
            return 'missing_quality_evidence'
        if bad(features[name]):
            return reason
    return 'good'


DRIVERS = {
    'density_slope_15s':'Recent 15-second density trend',
    'density_slope_30s':'Recent 30-second density trend',
    'density_change_15s':'Recent density change',
    'critical_margin':'Margin to the configured critical threshold',
    'critical_margin_ratio':'Relative margin to the configured critical threshold',
    'estimated_density':'Current estimated density',
    'neighbor_estimated_density_max':'Maximum neighboring-zone density',
    'coverage_ratio':'Observation coverage',
}


class RiskPredictor:
    def __init__(self, directory):
        self.root = Path(directory)
        self.frozen = json.loads((self.root/'frozen.json').read_text())
        for name,h in self.frozen['artifact_hashes'].items():
            if sha256(self.root/name) != h:
                raise ValueError(f'Frozen inference artifact corrupted: {name}')
        self.features = json.loads((self.root/'feature_list.json').read_text())
        self.calibrators = json.loads((self.root/'calibrators.json').read_text())
        if 'quantile_calibration' in self.frozen:
            offsets=json.loads((self.root/'quantile_calibration.json').read_text())['offsets']
            if offsets != self.frozen['quantile_calibration']:
                raise ValueError('Quantile calibration differs from frozen contract')
        self.models = {name:lgb.Booster(model_file=str(self.root/(name+'.txt'))) for name in ['classifier','p50','p90']}
        for model in self.models.values():
            if model.feature_name() != self.features:
                raise ValueError('Model feature ordering differs from contract')

    def predict(self, request):
        values = request.get('features',{})
        if set(values) != set(self.features):
            raise ValueError('Supply exactly the frozen feature list; never send labels, seeds or truth')
        try:
            arr = np.asarray([values[n] for n in self.features],dtype=float)
        except (TypeError,ValueError) as error:
            raise ValueError('Features must be numeric or null') from error
        reason = quality_reason(values,self.frozen['quality_policy'])
        if np.isinf(arr).any():
            reason = 'invalid_numeric_input'
        if reason == 'good' and not np.isfinite(arr).all():
            reason = 'missing_feature_history'
        response = dict(zoneId=request['zoneId'],horizonSeconds=30,probabilityCritical=None,
                        predictedDensity={'p50':None,'p90':None},estimatedSecondsToCritical=None,
                        decision='insufficient_data',dataQuality={'status':reason},topDrivers=[],
                        modelVersion=self.frozen['model_version'],source='synthetic_simulated',requiresHumanReview=True)
        if reason != 'good':
            return response
        x = pd.DataFrame([arr],columns=self.features)
        raw = self.models['classifier'].predict(x,raw_score=True,num_threads=2)[0]
        probability = float(calibrate(raw,self.calibrators['lightgbm']))
        q50 = max(0.,float(self.models['p50'].predict(x,num_threads=2)[0]) + self.frozen.get('quantile_calibration',{}).get('p50',0.))
        q90 = max(q50,float(self.models['p90'].predict(x,num_threads=2)[0]) + self.frozen.get('quantile_calibration',{}).get('p90',0.))
        observed_critical = values['estimated_density'] >= values['critical_threshold']
        threshold = self.frozen['thresholds']['lightgbm']['threshold']
        decision = 'currently_critical' if observed_critical else ('early_warning' if probability >= threshold else 'monitor')
        contribution = self.models['classifier'].predict(x,pred_contrib=True,num_threads=2)[0,:-1]
        top = np.argsort(np.abs(contribution))[-3:][::-1]
        slope = values['density_slope_15s']
        eta = (values['critical_threshold']-values['estimated_density'])/slope if slope > 0 and not observed_critical else None
        response.update(probabilityCritical=probability,predictedDensity={'p50':q50,'p90':q90},
                        densityTarget='maximum_density_next_30_seconds',decision=decision,
                        estimatedSecondsToCritical=float(eta) if eta is not None and 0 < eta <= 30 else None,
                        timeEstimateMethod='linear_trend_heuristic_not_a_trained_ETA',
                        topDrivers=[dict(feature=self.features[i],description=DRIVERS.get(self.features[i],self.features[i].replace('_',' ')),
                                         rawLogOddsContribution=float(contribution[i]),
                                         effect='increases_raw_score' if contribution[i]>0 else 'decreases_raw_score') for i in top],
                        explanationScale='raw_uncalibrated_model_log_odds_not_causal_effect',
                        operatingTargetMet=self.frozen['thresholds']['lightgbm']['constraints_met'])
        return response



