"""Stateful backend boundary, also used by evaluation via policy_v14.replay."""
import json
from .inference_v14 import RiskPredictor
from .policy_v14 import Policy, AlertState

class StatefulPredictor:
    def __init__(self,directory):
        self.predictor=RiskPredictor(directory)
        self.policy=Policy(**json.loads((self.predictor.root/'alert_policy.json').read_text()))
        self.states={}

    def predict(self,request):
        # The caller must construct features causally from the complete observation history.
        key=(request['venueId'],request['eventId'],request['zoneId'])
        response=self.predictor.predict(request)
        values=request['features']
        state=self.states.setdefault(key,AlertState(self.policy))
        response['decision']=state.update(request['timestampSeconds'],response['probabilityCritical'],
            quality_good=response['dataQuality']['status']=='good',
            observed_critical=values['estimated_density']>=values['critical_threshold'],
            complete_outage=values['coverage_ratio']==0)
        response['alertDeliveryVerified']=False
        return response
