using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using Aman.CameraControl;
using Aman.Simulation;
using Aman.UI;
using Aman.Zones;
using Unity.AI.Navigation;
using UnityEditor;
using UnityEditor.SceneManagement;
using UnityEngine;
using UnityEngine.AI;
using UnityEngine.Rendering;
using UnityEngine.Rendering.Universal;
using UnityEngine.SceneManagement;
using UnityEngine.UIElements;

namespace Aman.Editor
{
    public static class AmanSimulationStadiumGenerator
    {
        public const string ScenePath = "Assets/Scenes/AmanSimulationStadium.unity";
        public const string NavMeshPath = "Assets/Scenes/AmanSimulationStadium/NavMesh.asset";

        private const string MaterialFolder = "Assets/AmanSimulationStadium/Materials";
        private const string PersonPrefabPath = "Assets/Prefabs/Person.prefab";
        private const string OperatorPrefabPath = "Assets/Prefabs/Operator.prefab";
        private const string PanelSettingsPath = "Assets/UI Toolkit/PanelSettings.asset";
        private const string VisualTreePath = "Assets/UI/AmanUI.uxml";

        private sealed class Palette
        {
            public Material NormalFill;
            public Material WarningFill;
            public Material CriticalFill;
            public Material NormalStroke;
            public Material WarningStroke;
            public Material CriticalStroke;
            public Material Canvas;
            public Material Grid;
            public Material White;
            public Material Wall;
            public Material Structure;
            public Material Corridor;
            public Material Glass;
        }

        private readonly struct ZoneDefinition
        {
            public readonly string Id;
            public readonly string Name;
            public readonly int Order;
            public readonly Vector3 Center;
            public readonly Vector3 Size;
            public readonly Vector3 CameraPosition;
            public readonly Color GizmoColor;
            public readonly Material FloorMaterial;
            public readonly Material OutlineMaterial;

            public ZoneDefinition(string id, string name, int order, Vector3 center, Vector3 size,
                Vector3 cameraPosition, Color gizmoColor, Material floorMaterial, Material outlineMaterial)
            {
                Id = id;
                Name = name;
                Order = order;
                Center = center;
                Size = size;
                CameraPosition = cameraPosition;
                GizmoColor = gizmoColor;
                FloorMaterial = floorMaterial;
                OutlineMaterial = outlineMaterial;
            }
        }

        [MenuItem("Tools/AMAN/Generate Aman Simulation Stadium", false, 5)]
        public static void GenerateFromMenu()
        {
            if (EditorApplication.isPlayingOrWillChangePlaymode)
            {
                Debug.LogError("[AMAN Stadium] Exit Play Mode before generating the stadium scene.");
                return;
            }

            if (!EditorSceneManager.SaveCurrentModifiedScenesIfUserWantsTo()) return;
            Generate();
        }

        public static void GenerateFromCommandLine()
        {
            Generate();
        }

        public static void Generate()
        {
            EnsureFolder("Assets/Scenes/AmanSimulationStadium");
            if (AssetDatabase.IsValidFolder("Assets/AmanSimulationStadium"))
                AssetDatabase.DeleteAsset("Assets/AmanSimulationStadium");
            EnsureFolder(MaterialFolder);
            if (AssetDatabase.LoadAssetAtPath<NavMeshData>(NavMeshPath) != null)
                AssetDatabase.DeleteAsset(NavMeshPath);

            Scene scene = EditorSceneManager.NewScene(NewSceneSetup.EmptyScene, NewSceneMode.Single);
            Palette palette = CreatePalette();

            GameObject environment = new GameObject("Aman Simulation Stadium");
            GameObject walkable = Child(environment, "Walkable Surfaces");
            GameObject architecture = Child(environment, "Venue Architecture");
            GameObject decorations = Child(environment, "Navigation-Ignored Markings and Labels");

            NavMeshModifier architectureModifier = architecture.AddComponent<NavMeshModifier>();
            architectureModifier.overrideArea = true;
            architectureModifier.area = NavMesh.GetAreaFromName("Not Walkable");
            architectureModifier.applyToChildren = true;

            NavMeshModifier decorationModifier = decorations.AddComponent<NavMeshModifier>();
            decorationModifier.ignoreFromBuild = true;
            decorationModifier.applyToChildren = true;

            ZoneDefinition[] zones =
            {
                new ZoneDefinition("east-entrance", "East Entrance", 0,
                    new Vector3(-15f, 0f, 0f), new Vector3(30f, 6f, 30f), new Vector3(-15f, 47f, -10f),
                    new Color(0.20f, 0.90f, 0.40f, 0.25f), palette.NormalFill, palette.NormalStroke),
                new ZoneDefinition("north-concourse", "North Concourse", 1,
                    new Vector3(40f, 0f, 0f), new Vector3(80f, 6f, 30f), new Vector3(40f, 62f, -13f),
                    new Color(0.82f, 0.60f, 0.12f, 0.25f), palette.WarningFill, palette.WarningStroke),
                new ZoneDefinition("west-exit", "West Exit", 2,
                    new Vector3(115f, 0f, 0f), new Vector3(70f, 6f, 30f), new Vector3(115f, 58f, -13f),
                    new Color(0.20f, 0.90f, 0.40f, 0.25f), palette.NormalFill, palette.NormalStroke),
                new ZoneDefinition("south-concourse", "South Concourse", 3,
                    new Vector3(40f, 0f, -35f), new Vector3(50f, 6f, 20f), new Vector3(40f, 43f, -44f),
                    new Color(1.00f, 0.35f, 0.28f, 0.25f), palette.CriticalFill, palette.CriticalStroke)
            };

            foreach (ZoneDefinition zone in zones)
            {
                CreatePrimitive(PrimitiveType.Cube, $"{zone.Name} Floor", walkable.transform,
                    new Vector3(zone.Center.x, -0.2f, zone.Center.z),
                    new Vector3(zone.Size.x, 0.4f, zone.Size.z), zone.FloorMaterial);
            }

            BuildFrontendPlan(decorations.transform, zones, palette);
            BuildOperatorVenue(walkable.transform, architecture.transform, decorations.transform, palette);

            GameObject zoneRoot = Child(environment, "Simulation Zones (Backend Mapping Rectangles)");
            foreach (ZoneDefinition definition in zones)
                CreateZone(zoneRoot.transform, definition);

            CreateRuntimeSystems(zones);
            CreateLighting();
            CreateCamera();
            CreateUI();

            GameObject navigation = new GameObject("Navigation");
            NavMeshSurface surface = navigation.AddComponent<NavMeshSurface>();
            surface.collectObjects = CollectObjects.All;
            surface.useGeometry = NavMeshCollectGeometry.RenderMeshes;
            surface.layerMask = ~0;
            surface.overrideTileSize = true;
            surface.tileSize = 128;

            EditorSceneManager.SaveScene(scene, ScenePath);
            surface.BuildNavMesh();
            if (surface.navMeshData == null)
                throw new InvalidOperationException("NavMesh generation returned no data.");
            AssetDatabase.CreateAsset(surface.navMeshData, NavMeshPath);
            EditorUtility.SetDirty(surface);
            EditorSceneManager.SaveScene(scene, ScenePath);

            AddSceneToBuildSettings();
            AssetDatabase.SaveAssets();
            AssetDatabase.Refresh();
            ValidateGeneratedScene(surface, zones);

            Selection.activeGameObject = environment;
            SceneView.lastActiveSceneView?.FrameSelected();
            Debug.Log($"[AMAN Stadium] Generated and validated {ScenePath}. The existing Main Scene was not loaded or modified.");
        }

        private static void BuildFrontendPlan(Transform decorations, IReadOnlyCollection<ZoneDefinition> zones, Palette palette)
        {
            CreateMarking("Frontend Map Canvas", decorations, new Vector3(60f, -0.48f, -15f),
                new Vector3(196f, 0.5f, 76f), palette.Canvas);
            for (float x = -40f; x <= 160f; x += 10f)
                CreateMarking($"Grid X {x:0}", decorations, new Vector3(x, -0.215f, -15f),
                    new Vector3(0.10f, 0.02f, 80f), palette.Grid);
            for (float z = -55f; z <= 25f; z += 10f)
                CreateMarking($"Grid Z {z:0}", decorations, new Vector3(60f, -0.215f, z),
                    new Vector3(200f, 0.02f, 0.10f), palette.Grid);

            foreach (ZoneDefinition zone in zones)
            {
                float halfX = zone.Size.x * 0.5f;
                float halfZ = zone.Size.z * 0.5f;
                const float stroke = 0.38f;
                const float y = 0.035f;
                CreateMarking(zone.Name + " Border North", decorations,
                    new Vector3(zone.Center.x, y, zone.Center.z + halfZ), new Vector3(zone.Size.x + stroke, 0.07f, stroke), zone.OutlineMaterial);
                CreateMarking(zone.Name + " Border South", decorations,
                    new Vector3(zone.Center.x, y, zone.Center.z - halfZ), new Vector3(zone.Size.x + stroke, 0.07f, stroke), zone.OutlineMaterial);
                CreateMarking(zone.Name + " Border East", decorations,
                    new Vector3(zone.Center.x + halfX, y, zone.Center.z), new Vector3(stroke, 0.07f, zone.Size.z), zone.OutlineMaterial);
                CreateMarking(zone.Name + " Border West", decorations,
                    new Vector3(zone.Center.x - halfX, y, zone.Center.z), new Vector3(stroke, 0.07f, zone.Size.z), zone.OutlineMaterial);

                CreateWorldLabel(decorations, zone.Name.ToUpperInvariant(),
                    new Vector3(zone.Center.x - halfX + 1f, 0.08f, zone.Center.z + halfZ + 2.2f),
                    Quaternion.Euler(90f, 0f, 0f), palette.White, 0.72f, TextAnchor.MiddleLeft);
            }
        }

        private static void BuildOperatorVenue(Transform walkable, Transform architecture, Transform decorations, Palette palette)
        {
            const float outerHeight = 3.2f;
            const float dividerHeight = 2.45f;
            const float thickness = 0.55f;

            CreatePrimitive(PrimitiveType.Cube, "South Transfer Corridor Floor", walkable,
                new Vector3(40f, -0.2f, -20f), new Vector3(10f, 0.4f, 10f), palette.Corridor);

            CreateBarrier("Top Hall North Wall", architecture, new Vector3(60f, outerHeight * 0.5f, 15.3f),
                new Vector3(180.6f, outerHeight, thickness), palette.Wall);
            CreateBarrier("East Zone South Wall", architecture, new Vector3(-15f, outerHeight * 0.5f, -15.3f),
                new Vector3(30f, outerHeight, thickness), palette.Wall);
            CreateBarrier("North Hall South Wall Left", architecture, new Vector3(17.5f, outerHeight * 0.5f, -15.3f),
                new Vector3(35f, outerHeight, thickness), palette.Wall);
            CreateBarrier("North Hall South Wall Right", architecture, new Vector3(62.5f, outerHeight * 0.5f, -15.3f),
                new Vector3(35f, outerHeight, thickness), palette.Wall);
            CreateBarrier("West Zone South Wall", architecture, new Vector3(115f, outerHeight * 0.5f, -15.3f),
                new Vector3(70f, outerHeight, thickness), palette.Wall);

            foreach (float z in new[] { -10f, 10f })
            {
                CreateBarrier("East Entrance End Wall", architecture, new Vector3(-30.3f, outerHeight * 0.5f, z),
                    new Vector3(thickness, outerHeight, 10f), palette.Wall);
                CreateBarrier("West Exit End Wall", architecture, new Vector3(150.3f, outerHeight * 0.5f, z),
                    new Vector3(thickness, outerHeight, 10f), palette.Wall);
            }

            foreach (float x in new[] { 0f, 80f })
            foreach (float z in new[] { -10f, 10f })
                CreateBarrier($"Zone Divider {x:0} {z:+0;-0}", architecture,
                    new Vector3(x, dividerHeight * 0.5f, z), new Vector3(thickness, dividerHeight, 10f), palette.Structure);

            CreateBarrier("South Concourse North Wall Left", architecture, new Vector3(25f, outerHeight * 0.5f, -24.7f),
                new Vector3(20f, outerHeight, thickness), palette.Wall);
            CreateBarrier("South Concourse North Wall Right", architecture, new Vector3(55f, outerHeight * 0.5f, -24.7f),
                new Vector3(20f, outerHeight, thickness), palette.Wall);
            CreateBarrier("South Concourse South Wall", architecture, new Vector3(40f, outerHeight * 0.5f, -45.3f),
                new Vector3(50.6f, outerHeight, thickness), palette.Wall);
            CreateBarrier("South Concourse West Wall", architecture, new Vector3(14.7f, outerHeight * 0.5f, -35f),
                new Vector3(thickness, outerHeight, 20f), palette.Wall);
            CreateBarrier("South Concourse East Wall", architecture, new Vector3(65.3f, outerHeight * 0.5f, -35f),
                new Vector3(thickness, outerHeight, 20f), palette.Wall);
            CreateBarrier("Transfer Corridor West Wall", architecture, new Vector3(34.7f, dividerHeight * 0.5f, -20f),
                new Vector3(thickness, dividerHeight, 10f), palette.Structure);
            CreateBarrier("Transfer Corridor East Wall", architecture, new Vector3(45.3f, dividerHeight * 0.5f, -20f),
                new Vector3(thickness, dividerHeight, 10f), palette.Structure);

            CreateGatePortal(architecture, decorations, "EAST ENTRANCE", new Vector3(-30.25f, 0f, 0f), true,
                palette.NormalStroke, palette.White);
            CreateGatePortal(architecture, decorations, "WEST EXIT", new Vector3(150.25f, 0f, 0f), false,
                palette.NormalStroke, palette.White);
            CreateDoorHeader(architecture, "TO SOUTH CONCOURSE", new Vector3(40f, 3.15f, -15.1f), palette.CriticalStroke);
            CreateDoorHeader(architecture, "SOUTH ARRIVAL", new Vector3(40f, 3.15f, -24.9f), palette.CriticalStroke);

            for (float x = -20f; x <= 140f; x += 20f)
            {
                CreateColumn(architecture, new Vector3(x, 2.25f, 13.2f), palette.Structure);
                CreateBarrier($"North Canopy Beam {x:0}", architecture, new Vector3(x, 4.35f, 10.5f),
                    new Vector3(0.35f, 0.35f, 9f), palette.Structure);
            }
            CreateBarrier("North Canopy Spine", architecture, new Vector3(60f, 4.35f, 6.2f),
                new Vector3(170f, 0.35f, 0.35f), palette.Structure);

            foreach (float x in new[] { 18f, 40f, 62f })
            {
                CreateColumn(architecture, new Vector3(x, 2.25f, -42.8f), palette.Structure);
                CreateBarrier($"South Canopy Beam {x:0}", architecture, new Vector3(x, 4.35f, -40f),
                    new Vector3(0.35f, 0.35f, 8f), palette.Structure);
            }
            CreateBarrier("South Canopy Spine", architecture, new Vector3(40f, 4.35f, -36.2f),
                new Vector3(44f, 0.35f, 0.35f), palette.Structure);

            foreach (float x in new[] { 20f, 40f, 60f, 100f, 120f, 140f })
                CreateBarrier($"Observation Window {x:0}", architecture, new Vector3(x, 2.15f, 15.0f),
                    new Vector3(8f, 1.15f, 0.12f), palette.Glass);
        }

        private static void CreateGatePortal(Transform architecture, Transform decorations, string label,
            Vector3 position, bool facesEast, Material accent, Material textMaterial)
        {
            CreateBarrier(label + " North Pylon", architecture, position + new Vector3(0f, 2.35f, 5.5f),
                new Vector3(1.15f, 4.7f, 1.15f), accent);
            CreateBarrier(label + " South Pylon", architecture, position + new Vector3(0f, 2.35f, -5.5f),
                new Vector3(1.15f, 4.7f, 1.15f), accent);
            CreateBarrier(label + " Header", architecture, position + new Vector3(0f, 4.35f, 0f),
                new Vector3(1.15f, 0.7f, 12f), accent);
            CreateWorldLabel(decorations, label, position + new Vector3(facesEast ? 0.62f : -0.62f, 4.35f, 0f),
                Quaternion.Euler(0f, facesEast ? -90f : 90f, 0f), textMaterial, 0.62f);
        }

        private static void CreateDoorHeader(Transform parent, string name, Vector3 position, Material material)
        {
            CreateBarrier(name + " Header", parent, position, new Vector3(10f, 0.55f, 0.55f), material);
        }

        private static void CreateColumn(Transform parent, Vector3 position, Material material)
        {
            CreatePrimitive(PrimitiveType.Cylinder, $"Column {position.x:0} {position.z:0}", parent,
                position, new Vector3(0.55f, 2.25f, 0.55f), material);
        }

        private static void CreateZone(Transform parent, ZoneDefinition definition)
        {
            GameObject zoneObject = new GameObject("Zone_" + definition.Name.Replace(" ", "_"), typeof(Zone));
            zoneObject.transform.SetParent(parent, false);
            zoneObject.transform.position = definition.Center;
            Zone zone = zoneObject.GetComponent<Zone>();
            zone.id = definition.Id;
            zone.displayName = definition.Name;
            zone.order = definition.Order;
            zone.boundsCenter = new Vector3(0f, definition.Size.y * 0.5f, 0f);
            zone.boundsSize = definition.Size;
            zone.focusOffset = new Vector3(0f, 1.1f, 0f);
            zone.cameraViewOffset = definition.CameraPosition - definition.Center;
            zone.gizmoColor = definition.GizmoColor;
        }

        private static void CreateRuntimeSystems(IReadOnlyCollection<ZoneDefinition> zones)
        {
            GameObject runtime = new GameObject("AMAN Runtime Systems");
            GameObject attendeePrefab = AssetDatabase.LoadAssetAtPath<GameObject>(PersonPrefabPath);
            GameObject responderPrefab = AssetDatabase.LoadAssetAtPath<GameObject>(OperatorPrefabPath);
            if (attendeePrefab == null || responderPrefab == null)
                throw new InvalidOperationException("The existing AMAN crowd/responder prefabs could not be loaded.");

            runtime.AddComponent<SelectionManager>();
            RemoteAmanClient remote = runtime.AddComponent<RemoteAmanClient>();
            SerializedObject remoteObject = new SerializedObject(remote);
            remoteObject.FindProperty("attendeePrefab").objectReferenceValue = attendeePrefab;
            remoteObject.FindProperty("responderPrefab").objectReferenceValue = responderPrefab;
            remoteObject.FindProperty("coordinateOrigin").objectReferenceValue = runtime.transform;
            remoteObject.ApplyModifiedPropertiesWithoutUndo();
            runtime.AddComponent<VenueUIRuntimeBootstrapper>();

            GameObject manager = new GameObject("Zone Manager");
            manager.AddComponent<ZoneManager>();
        }

        private static void CreateLighting()
        {
            GameObject lightObject = new GameObject("Directional Light");
            Light light = lightObject.AddComponent<Light>();
            light.type = LightType.Directional;
            light.color = new Color(1f, 0.96f, 0.88f);
            light.intensity = 1.25f;
            light.shadows = LightShadows.Soft;
            lightObject.transform.rotation = Quaternion.Euler(48f, -32f, 0f);

            RenderSettings.ambientMode = AmbientMode.Trilight;
            RenderSettings.ambientSkyColor = new Color(0.31f, 0.39f, 0.52f);
            RenderSettings.ambientEquatorColor = new Color(0.22f, 0.24f, 0.28f);
            RenderSettings.ambientGroundColor = new Color(0.10f, 0.11f, 0.13f);
            RenderSettings.fog = false;
        }

        private static void CreateCamera()
        {
            GameObject cameraObject = new GameObject("Main Camera");
            cameraObject.tag = "MainCamera";
            Camera camera = cameraObject.AddComponent<Camera>();
            camera.clearFlags = CameraClearFlags.SolidColor;
            camera.backgroundColor = new Color(0.031f, 0.039f, 0.051f);
            camera.fieldOfView = 52f;
            camera.nearClipPlane = 0.2f;
            camera.farClipPlane = 600f;
            cameraObject.AddComponent<AudioListener>();
            cameraObject.AddComponent<UniversalAdditionalCameraData>();
            AmanCameraController controller = cameraObject.AddComponent<AmanCameraController>();
            controller.maxDistance = 300f;
            controller.keyPanSpeed = 28f;
            controller.enableZoneCutaway = false;
            Vector3 overviewTarget = new Vector3(60f, 0f, -15f);
            cameraObject.transform.position = new Vector3(60f, 105f, -92f);
            cameraObject.transform.rotation = Quaternion.LookRotation(
                overviewTarget - cameraObject.transform.position, Vector3.forward);
        }

        private static void CreateUI()
        {
            PanelSettings panelSettings = AssetDatabase.LoadAssetAtPath<PanelSettings>(PanelSettingsPath);
            VisualTreeAsset visualTree = AssetDatabase.LoadAssetAtPath<VisualTreeAsset>(VisualTreePath);
            if (panelSettings == null || visualTree == null)
                throw new InvalidOperationException("The existing AMAN PanelSettings or AmanUI.uxml asset could not be loaded.");

            GameObject uiObject = new GameObject("UIDocument");
            uiObject.layer = 5;
            UIDocument document = uiObject.AddComponent<UIDocument>();
            document.panelSettings = panelSettings;
            document.visualTreeAsset = visualTree;
        }

        private static Palette CreatePalette()
        {
            return new Palette
            {
                NormalFill = Material("Normal Fill", Hex("12241B")),
                WarningFill = Material("Warning Fill", Hex("241D10")),
                CriticalFill = Material("Critical Fill", Hex("2A1614")),
                NormalStroke = Material("Normal Stroke", Hex("42C98A"), false),
                WarningStroke = Material("Warning Stroke", Hex("D19A20"), false),
                CriticalStroke = Material("Critical Stroke", Hex("EF6559"), false),
                Canvas = Material("Map Canvas", Hex("080A0D"), false),
                Grid = Material("Map Grid", Hex("12171E"), false),
                White = Material("Map Text", Hex("EEF2F7"), false),
                Wall = LitMaterial("Venue Wall", Hex("26313B"), 0.18f),
                Structure = LitMaterial("Venue Structure", Hex("435262"), 0.42f),
                Corridor = LitMaterial("Transfer Corridor", Hex("303943"), 0.20f),
                Glass = LitMaterial("Observation Glass", Hex("1A4A60"), 0.72f)
            };
        }

        private static Color Hex(string value)
        {
            if (!ColorUtility.TryParseHtmlString("#" + value, out Color color))
                throw new ArgumentException($"Invalid colour: {value}");
            return color;
        }

        private static Material Material(string name, Color color, bool smooth = true)
        {
            string path = $"{MaterialFolder}/{name.Replace(" ", string.Empty)}.mat";
            Material existing = AssetDatabase.LoadAssetAtPath<Material>(path);
            Shader shader = Shader.Find("Universal Render Pipeline/Unlit") ?? Shader.Find("Unlit/Color");
            if (shader == null) throw new InvalidOperationException("No compatible Unlit shader is available.");
            Material material = existing != null ? existing : new Material(shader) { name = name };
            material.shader = shader;
            material.color = color;
            if (material.HasProperty("_BaseColor")) material.SetColor("_BaseColor", color);
            if (material.HasProperty("_Smoothness")) material.SetFloat("_Smoothness", smooth ? 0.23f : 0.05f);
            if (existing == null) AssetDatabase.CreateAsset(material, path);
            else EditorUtility.SetDirty(material);
            return material;
        }

        private static Material LitMaterial(string name, Color color, float smoothness)
        {
            string path = $"{MaterialFolder}/{name.Replace(" ", string.Empty)}.mat";
            Shader shader = Shader.Find("Universal Render Pipeline/Lit") ?? Shader.Find("Standard");
            if (shader == null) throw new InvalidOperationException("No compatible Lit shader is available.");
            Material material = new Material(shader) { name = name, color = color };
            if (material.HasProperty("_BaseColor")) material.SetColor("_BaseColor", color);
            if (material.HasProperty("_Smoothness")) material.SetFloat("_Smoothness", smoothness);
            AssetDatabase.CreateAsset(material, path);
            return material;
        }

        private static GameObject CreatePrimitive(PrimitiveType type, string name, Transform parent,
            Vector3 position, Vector3 scale, Material material, bool local = false)
        {
            GameObject instance = GameObject.CreatePrimitive(type);
            instance.name = name;
            instance.transform.SetParent(parent, false);
            if (local) instance.transform.localPosition = position;
            else instance.transform.position = position;
            instance.transform.localScale = scale;
            Renderer renderer = instance.GetComponent<Renderer>();
            if (renderer != null) renderer.sharedMaterial = material;
            GameObjectUtility.SetStaticEditorFlags(instance,
                StaticEditorFlags.BatchingStatic | StaticEditorFlags.OccludeeStatic);
            return instance;
        }

        private static void CreateBarrier(string name, Transform parent, Vector3 position, Vector3 scale,
            Material material, bool local = false)
        {
            CreatePrimitive(PrimitiveType.Cube, name, parent, position, scale, material, local);
        }

        private static void CreateMarking(string name, Transform parent, Vector3 position, Vector3 scale, Material material)
        {
            GameObject marking = CreatePrimitive(PrimitiveType.Cube, name, parent, position, scale, material);
            Collider collider = marking.GetComponent<Collider>();
            if (collider != null) UnityEngine.Object.DestroyImmediate(collider);
        }

        private static void CreateWorldLabel(Transform parent, string text, Vector3 position, Quaternion rotation,
            Material material, float characterSize, TextAnchor anchor = TextAnchor.MiddleCenter)
        {
            GameObject label = new GameObject(text + " Label");
            label.transform.SetParent(parent, false);
            label.transform.position = position;
            label.transform.rotation = rotation;
            TextMesh mesh = label.AddComponent<TextMesh>();
            mesh.text = text;
            mesh.anchor = anchor;
            mesh.alignment = anchor == TextAnchor.MiddleLeft ? TextAlignment.Left : TextAlignment.Center;
            mesh.characterSize = characterSize;
            mesh.fontSize = 42;
            mesh.color = material.color;
            mesh.fontStyle = FontStyle.Bold;
            MeshRenderer renderer = label.GetComponent<MeshRenderer>();
            renderer.shadowCastingMode = ShadowCastingMode.Off;
            renderer.receiveShadows = false;
        }

        private static GameObject Child(GameObject parent, string name)
        {
            GameObject child = new GameObject(name);
            child.transform.SetParent(parent.transform, false);
            return child;
        }

        private static void AddSceneToBuildSettings()
        {
            List<EditorBuildSettingsScene> scenes = EditorBuildSettings.scenes.ToList();
            int index = scenes.FindIndex(value => string.Equals(value.path, ScenePath, StringComparison.OrdinalIgnoreCase));
            if (index < 0) scenes.Add(new EditorBuildSettingsScene(ScenePath, true));
            else scenes[index] = new EditorBuildSettingsScene(ScenePath, true);
            EditorBuildSettings.scenes = scenes.ToArray();
        }

        private static void ValidateGeneratedScene(NavMeshSurface surface, IReadOnlyCollection<ZoneDefinition> definitions)
        {
            Zone[] zones = UnityEngine.Object.FindObjectsByType<Zone>(FindObjectsInactive.Exclude);
            string[] expectedIds = definitions.Select(value => value.Id).OrderBy(value => value).ToArray();
            string[] actualIds = zones.Select(value => value.id).OrderBy(value => value).ToArray();
            if (!expectedIds.SequenceEqual(actualIds))
                throw new InvalidOperationException("Generated scene does not contain exactly the four required backend zone IDs.");

            for (int i = 0; i < zones.Length; i++)
            for (int j = i + 1; j < zones.Length; j++)
            {
                Bounds a = zones[i].WorldBounds;
                Bounds b = zones[j].WorldBounds;
                float overlapX = Mathf.Min(a.max.x, b.max.x) - Mathf.Max(a.min.x, b.min.x);
                float overlapZ = Mathf.Min(a.max.z, b.max.z) - Mathf.Max(a.min.z, b.min.z);
                if (overlapX > 0.01f && overlapZ > 0.01f)
                    throw new InvalidOperationException($"Simulation zones overlap: {zones[i].id} and {zones[j].id}.");
            }

            if (surface == null || surface.navMeshData == null || AssetDatabase.GetAssetPath(surface.navMeshData) != NavMeshPath)
                throw new InvalidOperationException("The generated scene is missing its persisted NavMesh data.");
            if (UnityEngine.Object.FindAnyObjectByType<RemoteAmanClient>() == null ||
                UnityEngine.Object.FindAnyObjectByType<ZoneManager>() == null ||
                UnityEngine.Object.FindAnyObjectByType<AmanCameraController>() == null ||
                UnityEngine.Object.FindAnyObjectByType<UIDocument>() == null)
                throw new InvalidOperationException("The generated scene is missing one or more required AMAN runtime systems.");

            foreach (Zone zone in zones)
            {
                if (!NavMesh.SamplePosition(zone.FocusPoint, out _, 4f, NavMesh.AllAreas))
                    throw new InvalidOperationException($"Zone {zone.id} has no NavMesh near its focus point.");
            }

            Zone first = zones.OrderBy(value => value.order).First();
            foreach (Zone target in zones.Where(value => value != first))
            {
                if (!NavMesh.SamplePosition(first.FocusPoint, out NavMeshHit start, 4f, NavMesh.AllAreas) ||
                    !NavMesh.SamplePosition(target.FocusPoint, out NavMeshHit end, 4f, NavMesh.AllAreas))
                    throw new InvalidOperationException("Could not sample NavMesh endpoints for connectivity validation.");
                NavMeshPath path = new NavMeshPath();
                if (!NavMesh.CalculatePath(start.position, end.position, NavMesh.AllAreas, path) || path.status != NavMeshPathStatus.PathComplete)
                    throw new InvalidOperationException($"NavMesh is disconnected between {first.id} and {target.id}.");
            }
        }

        private static void EnsureFolder(string path)
        {
            string normalized = path.Replace('\\', '/');
            if (AssetDatabase.IsValidFolder(normalized)) return;
            string parent = Path.GetDirectoryName(normalized)?.Replace('\\', '/');
            string leaf = Path.GetFileName(normalized);
            if (string.IsNullOrEmpty(parent) || string.IsNullOrEmpty(leaf))
                throw new ArgumentException($"Invalid asset folder path: {path}");
            EnsureFolder(parent);
            AssetDatabase.CreateFolder(parent, leaf);
        }
    }
}
