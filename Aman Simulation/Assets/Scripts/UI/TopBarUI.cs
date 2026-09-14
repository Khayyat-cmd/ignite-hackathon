using UnityEngine;
using UnityEngine.UIElements;
using Aman.Zones;
using Aman.Simulation;

namespace Aman.UI
{
    public class TopBarUI : MonoBehaviour
    {
        [Header("Display Settings")]
        public string defaultOverviewName = "OVERVIEW";
        public string venuePrefix = "VENUE CONTROL / ";

        private Label zoneLabelText;
        private Label subtitleText;
        private Button homeButton;
        private Button languageButton;
        private VisualElement uiRoot;
        private VisualElement statusDot;
        private RemoteAmanClient remoteClient;
        private bool backendConnected;
        private string connectionMessage = "CONNECTING";

        private void Start()
        {
            AmanLocalization.LanguageChanged += RefreshLanguage;
            remoteClient = FindAnyObjectByType<RemoteAmanClient>();
            if (remoteClient != null)
            {
                remoteClient.OnConnectionChanged += UpdateConnectionDisplay;
                UpdateConnectionDisplay(remoteClient.ConnectionMessage, remoteClient.IsConnected);
            }
            if (ZoneManager.Instance != null)
            {
                ZoneManager.Instance.OnZoneSelected += UpdateZoneDisplay;
                ZoneManager.Instance.OnHomeSelected += UpdateHomeDisplay;

                if (ZoneManager.Instance.CurrentZone != null)
                {
                    UpdateZoneDisplay(ZoneManager.Instance.CurrentZone);
                }
                else
                {
                    UpdateHomeDisplay();
                }
            }
            if (remoteClient == null)
            {
                UpdateHomeDisplay();
            }
            RefreshLanguage();
        }

        private void OnDestroy()
        {
            if (homeButton != null)
            {
                homeButton.clicked -= OnHomeClicked;
            }
            if (languageButton != null) languageButton.clicked -= AmanLocalization.Toggle;
            AmanLocalization.LanguageChanged -= RefreshLanguage;
            if (remoteClient != null) remoteClient.OnConnectionChanged -= UpdateConnectionDisplay;

            if (ZoneManager.Instance != null)
            {
                ZoneManager.Instance.OnZoneSelected -= UpdateZoneDisplay;
                ZoneManager.Instance.OnHomeSelected -= UpdateHomeDisplay;
            }
        }

        private void OnHomeClicked()
        {
            if (ZoneManager.Instance != null)
            {
                ZoneManager.Instance.RequestHomeJump();
            }
        }

        public void UpdateZoneDisplay(Zone zone)
        {
            if (zoneLabelText != null)
            {
                zoneLabelText.text = zone != null ? AmanLocalization.Zone(zone.displayName) : AmanLocalization.Text(defaultOverviewName, "نظرة عامة");
            }

            if (subtitleText != null)
            {
                subtitleText.text = zone != null
                    ? AmanLocalization.Text($"{venuePrefix}ZONE {zone.order + 1}", $"التحكم بالموقع / المنطقة {zone.order + 1}")
                    : AmanLocalization.Text($"{venuePrefix}FULL VENUE", "التحكم بالموقع / كامل الموقع");
            }

            if (statusDot != null)
            {
                statusDot.style.backgroundColor = backendConnected ? new Color(0.15f, 0.85f, 0.45f, 1f) : new Color(0.95f, 0.55f, 0.15f, 1f);
            }
        }

        public void UpdateHomeDisplay()
        {
            if (zoneLabelText != null)
            {
                zoneLabelText.text = AmanLocalization.Text(defaultOverviewName, "نظرة عامة");
            }

            if (subtitleText != null)
            {
                subtitleText.text = AmanLocalization.Text($"{venuePrefix}FULL VENUE", "التحكم بالموقع / كامل الموقع");
            }

            if (statusDot != null)
            {
                statusDot.style.backgroundColor = backendConnected ? new Color(0.2f, 0.7f, 1f, 1f) : new Color(0.95f, 0.55f, 0.15f, 1f);
            }
        }

        private void UpdateConnectionDisplay(string message, bool connected)
        {
            backendConnected = connected;
            connectionMessage = message;
            if (statusDot != null)
                statusDot.style.backgroundColor = connected ? new Color(0.15f, 0.85f, 0.45f, 1f) : new Color(0.95f, 0.55f, 0.15f, 1f);
            if (subtitleText != null && !connected)
                subtitleText.text = AmanLocalization.Text($"{venuePrefix}{message}", $"التحكم بالموقع / {AmanLocalization.Connection(message)}");
        }

        private void RefreshLanguage()
        {
            if (uiRoot != null)
            {
                uiRoot.EnableInClassList("aman-root--rtl", AmanLocalization.IsArabic);
                uiRoot.languageDirection = AmanLocalization.IsArabic
                    ? LanguageDirection.RTL
                    : LanguageDirection.LTR;
                ApplyAdvancedText(uiRoot);
            }
            if (languageButton != null) languageButton.text = AmanLocalization.Text("العربية", "ENGLISH");
            if (homeButton != null) homeButton.text = AmanLocalization.Text("RESET OVERVIEW [H]", "إعادة العرض [H]");
            if (ZoneManager.Instance != null && ZoneManager.Instance.CurrentZone != null)
                UpdateZoneDisplay(ZoneManager.Instance.CurrentZone);
            else
                UpdateHomeDisplay();
            if (!backendConnected) UpdateConnectionDisplay(connectionMessage, false);
        }

        private static void ApplyAdvancedText(VisualElement element)
        {
            if (element is TextElement textElement)
                textElement.style.unityTextGenerator = TextGeneratorType.Advanced;
            foreach (VisualElement child in element.Children()) ApplyAdvancedText(child);
        }

        public void BindReferences(VisualElement root, Label zoneLabel, Label subLabel, Button homeBtn, Button languageBtn, VisualElement dot)
        {
            uiRoot = root;
            zoneLabelText = zoneLabel;
            subtitleText = subLabel;
            homeButton = homeBtn;
            languageButton = languageBtn;
            statusDot = dot;

            if (homeButton != null)
            {
                homeButton.clicked += OnHomeClicked;
            }
            if (languageButton != null) languageButton.clicked += AmanLocalization.Toggle;
        }
    }
}
