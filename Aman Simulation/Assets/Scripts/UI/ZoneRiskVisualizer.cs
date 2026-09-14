using System.Collections.Generic;
using Aman.Data;
using Aman.Models;
using Aman.Simulation;
using Aman.Zones;
using UnityEngine;

namespace Aman.UI
{
    public class ZoneRiskVisualizer : MonoBehaviour
    {
        private readonly Dictionary<string, Renderer> overlays = new Dictionary<string, Renderer>();
        private IDataSource source;
        private Material template;

        private void Start()
        {
            source = FindAnyObjectByType<RemoteAmanClient>();
            if (source != null) source.OnClustersUpdated += Apply;
            if (ZoneManager.Instance != null) ZoneManager.Instance.OnZonesUpdated += SyncOverlayVisibility;
            BuildOverlays();
        }

        private void OnDestroy()
        {
            if (source != null) source.OnClustersUpdated -= Apply;
            if (ZoneManager.Instance != null) ZoneManager.Instance.OnZonesUpdated -= SyncOverlayVisibility;
            if (template != null) Destroy(template);
        }

        private void BuildOverlays()
        {
            Shader shader = Shader.Find("Universal Render Pipeline/Unlit") ?? Shader.Find("Unlit/Color");
            if (shader == null) return;
            template = new Material(shader);
            template.SetFloat("_Surface", 1f);
            template.SetFloat("_Blend", 0f);
            template.SetFloat("_SrcBlend", (float)UnityEngine.Rendering.BlendMode.SrcAlpha);
            template.SetFloat("_DstBlend", (float)UnityEngine.Rendering.BlendMode.OneMinusSrcAlpha);
            template.SetFloat("_ZWrite", 0f);
            template.SetFloat("_AlphaClip", 0f);
            template.SetOverrideTag("RenderType", "Transparent");
            template.EnableKeyword("_SURFACE_TYPE_TRANSPARENT");
            template.DisableKeyword("_ALPHATEST_ON");
            template.renderQueue = 3000;

            if (ZoneManager.Instance == null) return;
            foreach (Zone zone in ZoneManager.Instance.GetZones())
            {
                GameObject overlay = GameObject.CreatePrimitive(PrimitiveType.Cube);
                overlay.name = $"RiskOverlay_{zone.id}";
                overlay.transform.SetParent(transform, true);
                Bounds bounds = zone.WorldBounds;
                overlay.transform.position = new Vector3(bounds.center.x, bounds.min.y + 0.08f, bounds.center.z);
                overlay.transform.localScale = new Vector3(bounds.size.x, 0.08f, bounds.size.z);
                Collider collider = overlay.GetComponent<Collider>();
                if (collider != null) Destroy(collider);
                Renderer renderer = overlay.GetComponent<Renderer>();
                renderer.shadowCastingMode = UnityEngine.Rendering.ShadowCastingMode.Off;
                renderer.receiveShadows = false;
                renderer.material = new Material(template);
                SetColor(renderer, new Color(0.1f, 0.75f, 0.45f, 0.025f));
                overlays[zone.id] = renderer;
            }
            SyncOverlayVisibility();
        }

        private void SyncOverlayVisibility()
        {
            if (ZoneManager.Instance == null) return;
            HashSet<string> activeIds = new HashSet<string>();
            foreach (Zone zone in ZoneManager.Instance.GetZones()) activeIds.Add(zone.id);
            foreach (KeyValuePair<string, Renderer> overlay in overlays)
                if (overlay.Value != null) overlay.Value.enabled = activeIds.Contains(overlay.Key);
        }

        private void Apply(List<Cluster> clusters)
        {
            foreach (Cluster cluster in clusters)
            {
                if (!overlays.TryGetValue(cluster.ZoneId, out Renderer renderer)) continue;
                Color color = cluster.Classification >= 3
                    ? new Color(0.95f, 0.12f, 0.08f, 0.14f)
                    : cluster.Classification == 2
                        ? new Color(1f, 0.65f, 0.05f, 0.09f)
                        : new Color(0.1f, 0.75f, 0.45f, 0.025f);
                SetColor(renderer, color);
            }
        }

        private static void SetColor(Renderer renderer, Color color)
        {
            renderer.material.color = color;
            if (renderer.material.HasProperty("_BaseColor")) renderer.material.SetColor("_BaseColor", color);
        }
    }
}
