using System;

namespace Aman.Models
{
    [Serializable]
    public class SelectionDetail
    {
        public string Id;
        public string EntityType;
        public string DisplayName;
        public string ZoneId;
        public bool IsReachable;
        public string Status;
    }
}
