<?php

namespace App\Enums;

enum EventType: string
{
    case DensityUpdated = 'density_updated';
    case DangerDetected = 'danger_detected';
    case ResponderSelected = 'responder_selected';
    case ResponseStarted = 'response_started';
    case ResponseAcknowledged = 'response_acknowledged';
    case IncidentResolved = 'incident_resolved';
    case ResponderUpdated = 'responder_updated';
    case PopulationUpdated = 'population_updated';
    case SimulationUpdated = 'simulation_updated';
    case SimulationStopped = 'simulation_stopped';
    case MissionMessage = 'mission_message';
    case IncidentAdviceReady = 'incident_advice_ready';
}
