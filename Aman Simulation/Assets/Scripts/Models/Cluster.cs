using System;

namespace Aman.Models
{
    [Serializable]
    public struct Cluster
    {
        public string ZoneId;
        public string CellId;
        public int DeviceCount;
        public float DensityPerSqm;
        public int Classification;
        public float ComputedAt;
    }
}
