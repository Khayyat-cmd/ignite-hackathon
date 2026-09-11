"""Observable temporal features; apply before excluding censored rows."""
import numpy as np
from .features import MODEL_FEATURE_COLUMNS

ROBUST=['median_density_15s','median_change_15s','rising_count_15s','near_warning_count_30s']
EXTRA=['coverage_change_15s','uncertainty_change_15s','neighbor_median_15s','recovery_count_15s']

def augment(frame):
    f=frame.sort_values(['run_id','zone_id','tick']).reset_index(drop=True).copy()
    keys=['run_id','zone_id']
    g=f.groupby(keys,sort=False)
    def rolling(column,n,method):
        return getattr(g[column].rolling(n,min_periods=1),method)().reset_index(level=keys,drop=True)
    f['median_density_15s']=rolling('estimated_density',4,'median')
    f['median_change_15s']=rolling('density_change_5s',4,'median')
    f['_rising']=f.density_change_5s.gt(0).astype(float)
    f['_near']=f.estimated_density.ge(f.warning_threshold).astype(float)
    f['_recovery']=f.density_change_5s.lt(0).astype(float)
    g=f.groupby(keys,sort=False)
    f['rising_count_15s']=rolling('_rising',4,'sum')
    f['near_warning_count_30s']=rolling('_near',7,'sum')
    f['coverage_change_15s']=f.coverage_ratio-g.coverage_ratio.shift(3)
    f['uncertainty_change_15s']=f.uncertain_ratio-g.uncertain_ratio.shift(3)
    f['neighbor_median_15s']=rolling('neighbor_estimated_density_max',4,'median')
    f['recovery_count_15s']=rolling('_recovery',4,'sum')
    return f.drop(columns=['_rising','_near','_recovery'])
