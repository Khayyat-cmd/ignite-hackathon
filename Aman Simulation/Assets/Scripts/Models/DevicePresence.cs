using System;
using UnityEngine;

namespace Aman.Models
{
    [Serializable]
    public struct DevicePresence
    {
        public string DeviceId;
        public string ZoneId;
        public Vector3 Position;
        public float LastSeenTimestamp;
        public bool IsReachable;
    }
}
