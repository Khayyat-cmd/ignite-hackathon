using System;
using System.Linq;
using UnityEditor;
using UnityEngine;
using Aman.Zones;

namespace Aman.Editor
{
    public static class ZoneCreationMenu
    {
        [MenuItem("GameObject/AMAN/Create Zone", false, 10)]
        [MenuItem("Tools/AMAN/Create Zone", false, 10)]
        public static void CreateZone()
        {
            Zone[] existingZones = UnityEngine.Object.FindObjectsByType<Zone>(FindObjectsInactive.Include);
            int nextIndex = existingZones.Length + 1;

            Vector3 spawnPosition = Vector3.zero;
            if (SceneView.lastActiveSceneView != null)
            {
                spawnPosition = SceneView.lastActiveSceneView.pivot;
                if (Physics.Raycast(spawnPosition + Vector3.up * 50f, Vector3.down, out RaycastHit hit, 100f))
                {
                    spawnPosition.y = hit.point.y;
                }
            }

            GameObject zoneObj = new GameObject($"Zone_{nextIndex:00}", typeof(Zone));
            zoneObj.transform.position = spawnPosition;

            Zone zone = zoneObj.GetComponent<Zone>();
            zone.id = $"zone-{nextIndex:00}";
            zone.displayName = $"Zone {nextIndex:00}";
            zone.order = existingZones.Length;
            zone.boundsCenter = new Vector3(0f, 3f, 0f);
            zone.boundsSize = new Vector3(30f, 6f, 30f);
            zone.focusOffset = new Vector3(0f, 1f, 0f);
            float hue = (nextIndex * 0.18f) % 1.0f;
            zone.gizmoColor = Color.HSVToRGB(hue, 0.75f, 0.95f);
            zone.gizmoColor = new Color(zone.gizmoColor.r, zone.gizmoColor.g, zone.gizmoColor.b, 0.25f);

            Undo.RegisterCreatedObjectUndo(zoneObj, "Create AMAN Zone");
            Selection.activeGameObject = zoneObj;

            Debug.Log($"[AMAN] Created new zone '{zone.displayName}' (ID: {zone.id}) at {spawnPosition}.");
        }

        [MenuItem("Tools/AMAN/Generate Default Stadium Zones", false, 15)]
        public static void GenerateStadiumZones()
        {
            Vector3 center = new Vector3(1743.85f, 3.87f, 1820f);

            var zoneDefs = new (string id, string name, Vector3 offset, Vector3 size, Color color)[]
            {
                ("north-concourse", "North Concourse", new Vector3(0f, 2f, 26f), new Vector3(50f, 6f, 18f), new Color(0.2f, 0.6f, 1f, 0.25f)),
                ("south-concourse", "South Concourse", new Vector3(0f, 2f, -26f), new Vector3(50f, 6f, 18f), new Color(1f, 0.5f, 0.2f, 0.25f)),
                ("east-stand", "East Stand", new Vector3(26f, 2f, 0f), new Vector3(18f, 6f, 40f), new Color(0.2f, 0.9f, 0.4f, 0.25f)),
                ("west-stand", "West Stand", new Vector3(-26f, 2f, 0f), new Vector3(18f, 6f, 40f), new Color(0.9f, 0.2f, 0.8f, 0.25f)),
                ("pitch", "Central Pitch", new Vector3(0f, 1f, 0f), new Vector3(32f, 4f, 32f), new Color(0.95f, 0.85f, 0.2f, 0.25f))
            };

            for (int i = 0; i < zoneDefs.Length; i++)
            {
                var def = zoneDefs[i];
                Zone existing = UnityEngine.Object.FindObjectsByType<Zone>(FindObjectsInactive.Include)
                    .FirstOrDefault(z => z.id == def.id);

                if (existing != null) continue;

                GameObject zoneObj = new GameObject($"Zone_{def.name.Replace(" ", "_")}", typeof(Zone));
                zoneObj.transform.position = center + def.offset;

                Zone zone = zoneObj.GetComponent<Zone>();
                zone.id = def.id;
                zone.displayName = def.name;
                zone.order = i;
                zone.boundsCenter = Vector3.zero;
                zone.boundsSize = def.size;
                zone.focusOffset = Vector3.zero;
                zone.gizmoColor = def.color;
                Undo.RegisterCreatedObjectUndo(zoneObj, "Generate Stadium Zones");
            }

            RescanZones();
            Debug.Log("[AMAN] Generated default stadium zones.");
        }

        [MenuItem("Tools/AMAN/Rescan & Sort All Zones", false, 20)]
        public static void RescanZones()
        {
            ZoneManager zm = UnityEngine.Object.FindAnyObjectByType<ZoneManager>();
            if (zm != null)
            {
                zm.RefreshZones();
                Debug.Log($"[AMAN] Rescanned {zm.GetZones().Count} zones in scene.");
            }
            else
            {
                Debug.LogWarning("[AMAN] No ZoneManager found in the current scene.");
            }
        }
    }
}
