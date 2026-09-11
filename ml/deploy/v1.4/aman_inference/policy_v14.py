"""Shared causal per-zone state machine for replay and service adapters."""
from dataclasses import dataclass
from collections import deque
import math

@dataclass(frozen=True)
class Policy:
    high: float
    low: float
    release: float
    k: int=1
    n: int=1
    release_ticks: int=1
    minimum_seconds: int=0
    cooldown_seconds: int=0

    def __post_init__(self):
        if not 0 <= self.release < self.low <= self.high <= 1: raise ValueError('Invalid thresholds')
        if (self.k,self.n) not in {(1,1),(2,3),(2,2),(3,4)}: raise ValueError('Invalid confirmation window')
        if self.release_ticks not in {1,2,3}: raise ValueError('Invalid release persistence')
        if self.minimum_seconds not in {0,10,15,20} or self.cooldown_seconds not in {0,15,30,60}: raise ValueError('Invalid durations')

class AlertState:
    def __init__(self,policy):
        self.policy=policy; self.history=deque(maxlen=policy.n)
        self.last=None; self.active=False; self.started=None; self.below=0; self.cool_until=-1

    def update(self,seconds,probability,quality_good,observed_critical,complete_outage=False):
        if self.last is not None and seconds<=self.last: raise ValueError('Samples must be strictly ordered')
        if self.last is not None and seconds-self.last!=5:
            self.history.clear(); self.active=False; self.below=0; self.started=None; self.cool_until=-1
        self.last=seconds
        if complete_outage or not quality_good or probability is None or not math.isfinite(probability):
            self.history.clear(); self.active=False; self.below=0; self.started=None
            return 'outage' if complete_outage else 'insufficient_data'
        if observed_critical:
            self.history.clear(); self.active=False; self.below=0; self.started=None
            return 'currently_critical'
        p=self.policy; self.history.append(probability>=p.low)
        if self.active:
            self.below=self.below+1 if probability<p.release else 0
            if seconds-self.started>=p.minimum_seconds and self.below>=p.release_ticks:
                self.active=False; self.below=0; self.cool_until=seconds+p.cooldown_seconds
                self.history.clear()
        elif probability>=p.high or (seconds>=self.cool_until and len(self.history)>=p.k and sum(self.history)>=p.k):
            # High-confidence path overrides cooldown, but never poor quality.
            self.active=True; self.started=seconds; self.below=0
        return 'early_warning' if self.active else 'monitor'

def replay(frame,probability,policy):
    import numpy as np
    states=np.full(len(frame),'monitor',dtype=object)
    for _,indices in frame.groupby(['run_id','zone_id'],sort=False).indices.items():
        g=frame.iloc[indices]; machine=AlertState(policy)
        for i,t,good,critical,coverage in zip(indices,g.tick,g.insufficient_quality.eq(0),
                g.estimated_density.ge(g.critical_threshold),g.coverage_ratio):
            states[i]=machine.update(int(t)*5,float(probability[i]),bool(good),bool(critical),bool(coverage==0))
    return states
