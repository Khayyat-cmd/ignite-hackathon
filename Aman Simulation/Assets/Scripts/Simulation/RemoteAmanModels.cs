using System;

namespace Aman.Simulation
{
    [Serializable] public class SimulationListResponse { public SimulationPagination data; }
    [Serializable] public class SimulationPagination { public SimulationListItem[] data; }
    [Serializable] public class SimulationListItem { public int id; public string status; public int revision; }

    [Serializable]
    public class AmanSnapshot
    {
        public int id;
        public string status;
        public int revision;
        public int elapsedSeconds;
        public int attendeeCount;
        public string phase;
        public string observedAt;
        public bool stale;
        public int pollIntervalSeconds;
        public BackendZone[] zones;
        public BackendResponder[] responders;
        public BackendResponderPosition[] responderPositions;
        public BackendPosition[] positions;
        public BackendIncident[] incidents;
        public BackendCoordinateSystem coordinateSystem;
        public BackendFocus focus;
        public int nextOffset;
    }

    [Serializable] public class BackendPosition { public string id; public string zoneId; public string quality; public float x; public float z; public string observedAt; }
    [Serializable] public class BackendResponderPosition { public string id; public float x; public float z; public bool available; public string missionStatus; }
    [Serializable] public class BackendGeoPoint { public float latitude; public float longitude; }
    [Serializable] public class BackendCoordinateSystem { public BackendGeoPoint origin; public string units; public string x; public string z; }
    [Serializable]
    public class BackendFocus
    {
        public string zoneId;
        public string zoneKey;
        public string zoneName;
        public string incidentId;
        public int sequence;
        public string setAt;
        public BackendFocusCamera camera;
    }
    [Serializable]
    public class BackendFocusCamera
    {
        public float x;
        public float z;
        public float width;
        public float depth;
        public string units;
    }
    [Serializable] public class LatestReading { public int deviceCount; public float densityPerSquareMeter; }
    [Serializable]
    public class BackendZone
    {
        public string id;
        public string name;
        public float area_sqm;
        public float warning_density;
        public float critical_density;
        public string risk_level;
        public LatestReading latest_reading;
        public BackendGeoPoint[] boundary;
    }
    [Serializable]
    public class ReachabilitySignal { public bool dataReachable; public string status; }
    [Serializable] public class BackendSignals { public ReachabilitySignal reachability; }
    [Serializable]
    public class BackendResponder
    {
        public string id;
        public string name;
        public string role;
        public bool available;
        public BackendSignals signals;
    }
    [Serializable]
    public class BackendIncident
    {
        public string id;
        public string zone_id;
        public string active_zone_id;
        public string status;
        public string responder_id;
        public string assigned_responder_id;
        public BackendDecision decision;
    }

    [Serializable] public class BackendCandidate { public string responderId; public float distanceMeters; }
    [Serializable] public class BackendDecision { public BackendCandidate[] candidates; }
    [Serializable]
    public class RouteReviewRequest
    {
        public bool routeReviewed = true;
        public string responderId;
    }
    [Serializable] public class ResolveRequest { public string note = "Operator confirmed stable recovery."; }
}
