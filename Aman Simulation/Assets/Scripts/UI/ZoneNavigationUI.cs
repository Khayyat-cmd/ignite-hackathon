using System.Collections.Generic;
using Aman.Data;
using Aman.Models;
using Aman.Simulation;
using UnityEngine;
using UnityEngine.UIElements;
using Aman.Zones;

namespace Aman.UI
{
    public class ZoneNavigationUI : MonoBehaviour
    {
        private VisualElement buttonContainer;
        private Label headerLabel;
        private IDataSource dataSource;
        private List<Cluster> latestClusters = new List<Cluster>();

        private List<ZoneButtonEntry> buttonEntries = new List<ZoneButtonEntry>();

        private struct ZoneButtonEntry
        {
            public Zone zone;
            public VisualElement root;
            public Label label;
            public Label indexLabel;
            public Label statsLabel;
        }

        private void Start()
        {
            AmanLocalization.LanguageChanged += OnLanguageChanged;
            if (ZoneManager.Instance != null)
            {
                ZoneManager.Instance.OnZonesUpdated += RebuildButtons;
                ZoneManager.Instance.OnZoneSelected += OnZoneSelected;
                ZoneManager.Instance.OnHomeSelected += OnHomeSelected;
            }

            dataSource = FindAnyObjectByType<RemoteAmanClient>();
            if (dataSource != null) dataSource.OnClustersUpdated += OnClustersUpdated;

            RebuildButtons();
        }

        private void OnDestroy()
        {
            if (ZoneManager.Instance != null)
            {
                ZoneManager.Instance.OnZonesUpdated -= RebuildButtons;
                ZoneManager.Instance.OnZoneSelected -= OnZoneSelected;
                ZoneManager.Instance.OnHomeSelected -= OnHomeSelected;
            }
            if (dataSource != null) dataSource.OnClustersUpdated -= OnClustersUpdated;
            AmanLocalization.LanguageChanged -= OnLanguageChanged;
        }

        public void RebuildButtons()
        {
            foreach (var entry in buttonEntries)
            {
                entry.root?.RemoveFromHierarchy();
            }
            buttonEntries.Clear();

            if (ZoneManager.Instance == null || buttonContainer == null) return;

            IReadOnlyList<Zone> zones = ZoneManager.Instance.GetZones();

            for (int i = 0; i < zones.Count; i++)
            {
                Zone z = zones[i];
                if (z == null) continue;

                int displayIndex = i + 1;
                CreateZoneButton(z, displayIndex);
            }

            HighlightActiveZone(ZoneManager.Instance.CurrentZone);
            ApplyClusterStats();
        }

        private void CreateZoneButton(Zone zone, int displayIndex)
        {
            var btn = new VisualElement();
            btn.AddToClassList("zone-nav__button");

            btn.RegisterCallback<ClickEvent>(evt =>
            {
                if (ZoneManager.Instance != null)
                {
                    ZoneManager.Instance.RequestZoneJump(zone);
                }
            });

            var idxLabel = new Label($"{displayIndex:00}");
            idxLabel.AddToClassList("zone-nav__button-index");
            idxLabel.pickingMode = PickingMode.Ignore;
            btn.Add(idxLabel);

            var copy = new VisualElement();
            copy.AddToClassList("zone-nav__button-copy");
            copy.pickingMode = PickingMode.Ignore;

            var nameLabel = new Label(AmanLocalization.Zone(zone.displayName));
            nameLabel.AddToClassList("zone-nav__button-label");
            nameLabel.pickingMode = PickingMode.Ignore;
            copy.Add(nameLabel);

            var statsLabel = new Label(AmanLocalization.Text("WAITING FOR LIVE DATA", "بانتظار البيانات المباشرة"));
            statsLabel.AddToClassList("zone-nav__button-stats");
            statsLabel.pickingMode = PickingMode.Ignore;
            copy.Add(statsLabel);
            btn.Add(copy);

            buttonContainer.Add(btn);

            buttonEntries.Add(new ZoneButtonEntry
            {
                zone = zone,
                root = btn,
                label = nameLabel,
                indexLabel = idxLabel,
                statsLabel = statsLabel
            });
        }

        private void OnClustersUpdated(List<Cluster> clusters)
        {
            latestClusters = clusters ?? new List<Cluster>();
            ApplyClusterStats();
        }

        private void ApplyClusterStats()
        {
            foreach (ZoneButtonEntry entry in buttonEntries)
            {
                int clusterIndex = latestClusters.FindIndex(value => value.ZoneId == entry.zone.id);
                bool hasCluster = clusterIndex >= 0;
                Cluster cluster = hasCluster ? latestClusters[clusterIndex] : default;
                entry.statsLabel.text = !hasCluster
                    ? AmanLocalization.Text("WAITING FOR LIVE DATA", "بانتظار البيانات المباشرة")
                    : AmanLocalization.Text($"{cluster.DeviceCount:N0} DEVICES  ·  {cluster.DensityPerSqm:0.00}/m²",
                        $"{cluster.DeviceCount:N0} جهاز  ·  {cluster.DensityPerSqm:0.00}/م²");
                entry.root.EnableInClassList("zone-nav__button--warning", hasCluster && cluster.Classification == 2);
                entry.root.EnableInClassList("zone-nav__button--critical", hasCluster && cluster.Classification >= 3);
            }
        }

        private void OnZoneSelected(Zone zone)
        {
            HighlightActiveZone(zone);
        }

        private void OnHomeSelected()
        {
            HighlightActiveZone(null);
        }

        private void HighlightActiveZone(Zone activeZone)
        {
            foreach (var entry in buttonEntries)
            {
                bool isActive = activeZone != null && entry.zone == activeZone;
                if (isActive)
                {
                    entry.root.AddToClassList("zone-nav__button--active");
                }
                else
                {
                    entry.root.RemoveFromClassList("zone-nav__button--active");
                }
            }
        }

        private void OnLanguageChanged()
        {
            if (headerLabel != null) headerLabel.text = AmanLocalization.Text("VENUE ZONES", "مناطق الموقع");
            RebuildButtons();
        }

        public void SetReferences(VisualElement container, Label header)
        {
            buttonContainer = container;
            headerLabel = header;
            if (headerLabel != null) headerLabel.text = AmanLocalization.Text("VENUE ZONES", "مناطق الموقع");
        }
    }
}
