using System;

namespace Aman.Models
{
    [Serializable]
    public class Incident
    {
        public string IncidentId;
        public string ZoneId;
        public string CellId;
        public float StartedAt;
        public bool IsResolved;
        public string Status;
        public string ResponderId;
        public string ResponderName;
        public bool RouteReviewed;
    }
}
