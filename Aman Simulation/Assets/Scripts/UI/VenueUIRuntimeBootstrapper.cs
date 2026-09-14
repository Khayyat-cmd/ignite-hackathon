using UnityEngine;
using UnityEngine.UIElements;
using Aman.Zones;

namespace Aman.UI
{
    [DefaultExecutionOrder(-40)]
    public class VenueUIRuntimeBootstrapper : MonoBehaviour
    {
        [Header("UI Construction")]
        [Tooltip("Auto-bind UI scripts to the UIDocument in the scene")]
        public bool autoConstructUI = true;

        [RuntimeInitializeOnLoadMethod(RuntimeInitializeLoadType.AfterSceneLoad)]
        private static void OnSceneLoaded()
        {
            if (FindAnyObjectByType<VenueUIRuntimeBootstrapper>() == null)
            {
                GameObject bootstrapperObj = new GameObject("AMAN_VenueBootstrapper", typeof(VenueUIRuntimeBootstrapper));
            }
        }

        private void Awake()
        {
            EnsureRemoteBackendClient();
            EnsureZoneManager();
            EnsureRiskVisualizer();
            EnsureCameraController();

            if (!autoConstructUI) return;

            BindUIDocument();
        }

        private void EnsureRiskVisualizer()
        {
            if (FindAnyObjectByType<Aman.UI.ZoneRiskVisualizer>() == null)
            {
                gameObject.AddComponent<Aman.UI.ZoneRiskVisualizer>();
            }
        }

        private void EnsureRemoteBackendClient()
        {
            if (FindAnyObjectByType<Aman.Simulation.RemoteAmanClient>() == null)
            {
                gameObject.AddComponent<Aman.Simulation.RemoteAmanClient>();
            }
        }

        private void EnsureCameraController()
        {
            Camera mainCam = Camera.main;
            if (mainCam == null) return;

            OrbitCamera orbit = mainCam.GetComponent<OrbitCamera>();
            if (orbit != null)
            {
                orbit.enabled = false;
            }

            if (mainCam.GetComponent<Aman.CameraControl.AmanCameraController>() == null)
            {
                mainCam.gameObject.AddComponent<Aman.CameraControl.AmanCameraController>();
            }
        }

        private void EnsureZoneManager()
        {
            if (ZoneManager.Instance == null && FindAnyObjectByType<ZoneManager>() == null)
            {
                GameObject zmObj = new GameObject("ZoneManager", typeof(ZoneManager));
            }
        }

        private void BindUIDocument()
        {
            if (FindAnyObjectByType<TopBarUI>() != null && FindAnyObjectByType<ZoneNavigationUI>() != null)
            {
                return;
            }

            UIDocument uiDoc = FindAnyObjectByType<UIDocument>();
            if (uiDoc == null)
            {
                Debug.LogWarning(
                    "VenueUIRuntimeBootstrapper: No UIDocument found in scene.\n" +
                    "To enable the AMAN UI:\n" +
                    "  1. Create a PanelSettings asset (right-click Project > Create > UI Toolkit > Panel Settings Asset)\n" +
                    "  2. Add an empty GameObject to the scene\n" +
                    "  3. Add a UIDocument component to it\n" +
                    "  4. Assign the PanelSettings and Assets/UI/AmanUI.uxml"
                );
                return;
            }

            VisualElement root = uiDoc.rootVisualElement;

            root.pickingMode = PickingMode.Ignore;

            BindTopBar(root);

            BindZoneNav(root);

            BindIncidentActions(root);
        }

        private void BindIncidentActions(VisualElement root)
        {
            VisualElement actionBar = root.Q("incident-action-bar");
            if (actionBar == null) return;
            IncidentActionUI actions = gameObject.AddComponent<IncidentActionUI>();
            actions.Bind(actionBar);
        }

        private void BindTopBar(VisualElement root)
        {
            Label zoneLabel = root.Q<Label>("zone-label");
            Label subtitleLabel = root.Q<Label>("subtitle-label");
            Button homeButton = root.Q<Button>("home-button");
            Button languageButton = root.Q<Button>("language-button");
            VisualElement statusDot = root.Q("status-dot");

            if (zoneLabel == null || subtitleLabel == null || homeButton == null || languageButton == null || statusDot == null)
            {
                Debug.LogWarning("VenueUIRuntimeBootstrapper: Could not find all top-bar elements in the UXML. Check element names.");
                return;
            }

            TopBarUI topBarUI = gameObject.AddComponent<TopBarUI>();
            topBarUI.BindReferences(root, zoneLabel, subtitleLabel, homeButton, languageButton, statusDot);
        }

        private void BindZoneNav(VisualElement root)
        {
            VisualElement buttonsContainer = root.Q("zone-buttons-container");
            Label header = root.Q<Label>("zone-nav-header");

            if (buttonsContainer == null)
            {
                Debug.LogWarning("VenueUIRuntimeBootstrapper: Could not find 'zone-buttons-container' in the UXML. Check element name.");
                return;
            }

            ZoneNavigationUI navUI = gameObject.AddComponent<ZoneNavigationUI>();
            navUI.SetReferences(buttonsContainer, header);
        }
    }
}
