import numpy as np
from scipy.special import expit
from sklearn.linear_model import LogisticRegression
from sklearn.isotonic import IsotonicRegression
from sklearn.metrics import brier_score_loss

def calibrate(raw,spec):
    raw=np.asarray(raw)
    if spec['method']=='beta':
        p=np.clip(expit(raw),1e-7,1-1e-7)
        return expit(spec['a']*np.log(p)+spec['b']*np.log1p(-p)+spec['intercept'])
    if spec['method']=='shrunk_isotonic':
        platt=calibrate(raw,spec['platt'])
        iso=np.interp(raw,spec['x'],spec['y'])
        return .5*platt+.5*iso
    return expit(spec['slope']*raw+spec['intercept'])

def fit(raw,y,method):
    raw=np.asarray(raw); y=np.asarray(y)
    if len(np.unique(y))!=2: raise ValueError('Calibration needs both classes')
    if method=='beta':
        p=np.clip(expit(raw),1e-7,1-1e-7)
        m=LogisticRegression(C=1.,max_iter=1000).fit(np.c_[np.log(p),np.log1p(-p)],y)
        # Reject nonmonotone beta mappings by caller.
        return dict(method=method,a=float(m.coef_[0,0]),b=float(m.coef_[0,1]),intercept=float(m.intercept_[0]))
    if method=='shrunk_isotonic':
        m=IsotonicRegression(out_of_bounds='clip').fit(raw,y)
        return dict(method=method,x=m.X_thresholds_.tolist(),y=m.y_thresholds_.tolist(),platt=fit(raw,y,'platt'))
    m=LogisticRegression(C=1e6,max_iter=1000).fit(raw.reshape(-1,1),y)
    return dict(method='platt',slope=float(m.coef_[0,0]),intercept=float(m.intercept_[0]))
