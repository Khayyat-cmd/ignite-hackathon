using System;
using System.Collections.Generic;
using System.Linq;
using UnityEngine;

namespace Aman.Zones
{
    [DefaultExecutionOrder(-50)]
    public class ZoneManager : MonoBehaviour
    {
        public static ZoneManager Instance { get; private set; }

        [Header("Zone Registry")]
        [SerializeField] private List<Zone> zones = new List<Zone>();

        public Zone CurrentZone { get; private set; }

        public event Action<Zone> OnZoneSelected;

        public event Action OnHomeSelected;

        public event Action<Zone> OnZoneJumpRequested;

        public event Action OnHomeJumpRequested;

        public event Action OnZonesUpdated;

        private void Awake()
        {
            if (Instance != null && Instance != this)
            {
                Destroy(gameObject);
                return;
            }
            Instance = this;
            RefreshZones();
        }

        private void OnEnable()
        {
            if (Instance == null)
            {
                Instance = this;
            }
        }

        private void Start()
        {
            if (zones.Count == 0)
            {
                RefreshZones();
            }
        }

        public void RefreshZones()
        {
            zones = FindObjectsByType<Zone>(FindObjectsInactive.Exclude)
                .Where(z => z.isActiveAndEnabled)
                .OrderBy(z => z.order)
                .ThenBy(z => z.displayName)
                .ToList();

            OnZonesUpdated?.Invoke();
        }

        public IReadOnlyList<Zone> GetZones()
        {
            return zones.AsReadOnly();
        }

        public void RequestZoneJump(Zone zone)
        {
            CurrentZone = zone;
            OnZoneSelected?.Invoke(zone);
            OnZoneJumpRequested?.Invoke(zone);
        }

        public void RequestHomeJump()
        {
            CurrentZone = null;
            OnHomeSelected?.Invoke();
            OnHomeJumpRequested?.Invoke();
        }

        public void SetZoneFromNavigation(Zone zone)
        {
            if (CurrentZone == zone) return;

            CurrentZone = zone;
            if (zone != null)
            {
                OnZoneSelected?.Invoke(zone);
            }
            else
            {
                OnHomeSelected?.Invoke();
            }
        }

        public Bounds GetVenueBounds()
        {
            if (zones == null || zones.Count == 0)
            {
                return new Bounds(Vector3.zero, new Vector3(100f, 20f, 100f));
            }

            Bounds composite = zones[0].WorldBounds;
            for (int i = 1; i < zones.Count; i++)
            {
                if (zones[i] != null)
                {
                    composite.Encapsulate(zones[i].WorldBounds);
                }
            }

            return composite;
        }
    }
}
