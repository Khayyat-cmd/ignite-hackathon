using System;
using System.Collections.Generic;
using System.Linq;
using Aman.CameraControl;
using Aman.Simulation;
using Aman.Zones;
using UnityEngine;
using UnityEngine.UIElements;

namespace Aman.UI
{
    public class IncidentActionUI : MonoBehaviour
    {
        private RemoteAmanClient client;
        private VisualElement root;
        private Label title;
        private Label detail;
        private Label feedback;
        private Toggle routeReviewed;
        private DropdownField responderChoice;
        private Button primaryButton;
        private Button viewZoneButton;
        private BackendIncident incident;
        private Zone incidentZone;
        private BackendCandidate[] candidates = Array.Empty<BackendCandidate>();
        private AmanSnapshot lastSnapshot;
        private bool busy;

        private void Start()
        {
            AmanLocalization.LanguageChanged += OnLanguageChanged;
            client = FindAnyObjectByType<RemoteAmanClient>();
            if (client == null) return;
            client.OnSnapshotUpdated += Refresh;
            client.OnConnectionChanged += OnConnectionChanged;
            if (client.CurrentSnapshot != null) Refresh(client.CurrentSnapshot);
        }

        private void OnDestroy()
        {
            if (client != null)
            {
                client.OnSnapshotUpdated -= Refresh;
                client.OnConnectionChanged -= OnConnectionChanged;
            }
            if (primaryButton != null) primaryButton.clicked -= Submit;
            if (viewZoneButton != null) viewZoneButton.clicked -= ViewIncidentZone;
            AmanLocalization.LanguageChanged -= OnLanguageChanged;
        }

        public void Bind(VisualElement container)
        {
            root = container;
            title = root.Q<Label>("incident-title");
            detail = root.Q<Label>("incident-detail");
            feedback = root.Q<Label>("incident-feedback");
            routeReviewed = root.Q<Toggle>("route-reviewed");
            responderChoice = root.Q<DropdownField>("responder-choice");
            primaryButton = root.Q<Button>("incident-primary-action");
            viewZoneButton = root.Q<Button>("view-incident-zone");
            if (primaryButton != null) primaryButton.clicked += Submit;
            if (viewZoneButton != null) viewZoneButton.clicked += ViewIncidentZone;
            Hide();
        }

        private void Refresh(AmanSnapshot snapshot)
        {
            lastSnapshot = snapshot;
            incident = snapshot.incidents?.FirstOrDefault(value => !string.IsNullOrEmpty(value.active_zone_id));
            if (incident == null) { Hide(); return; }
            BackendZone zone = snapshot.zones?.FirstOrDefault(value => value.id == incident.zone_id);
            BackendResponder responder = snapshot.responders?.FirstOrDefault(value => value.id == (incident.assigned_responder_id ?? incident.responder_id));
            string zoneName = AmanLocalization.Zone(zone?.name) ?? AmanLocalization.Text("UNKNOWN ZONE", "منطقة غير معروفة");
            title.text = AmanLocalization.Text($"INCIDENT · {zoneName}", $"حادث · {zoneName}");
            detail.text = AmanLocalization.Status(incident.status) + (responder != null
                ? $"  ·  {responder.name}"
                : AmanLocalization.Text("  ·  NO ELIGIBLE RESPONDER", "  ·  لا يوجد مستجيب مؤهل"));
            feedback.text = string.Empty;
            routeReviewed.style.display = incident.status == "awaiting_approval" ? DisplayStyle.Flex : DisplayStyle.None;
            candidates = incident.decision?.candidates ?? Array.Empty<BackendCandidate>();
            if (responderChoice != null)
            {
                List<string> choices = candidates.Select(candidate =>
                {
                    BackendResponder candidateResponder = snapshot.responders?.FirstOrDefault(value => value.id == candidate.responderId);
                    string name = candidateResponder?.name ?? candidate.responderId ?? AmanLocalization.Text("Unknown responder", "مستجيب غير معروف");
                    return AmanLocalization.Text($"{name} · {candidate.distanceMeters:0} m", $"{name} · {candidate.distanceMeters:0} م");
                }).ToList();
                responderChoice.choices = choices;
                int selectedIndex = Array.FindIndex(candidates, value => value.responderId == incident.responder_id);
                responderChoice.index = choices.Count == 0 ? -1 : Mathf.Max(0, selectedIndex);
                responderChoice.style.display = incident.status == "awaiting_approval" && choices.Count > 0
                    ? DisplayStyle.Flex
                    : DisplayStyle.None;
            }
            primaryButton.style.display = incident.status == "awaiting_approval" || incident.status == "acknowledged" ? DisplayStyle.Flex : DisplayStyle.None;
            if (routeReviewed != null) routeReviewed.label = AmanLocalization.Text("Route reviewed", "تمت مراجعة المسار");
            if (responderChoice != null) responderChoice.label = AmanLocalization.Text("Responder", "المستجيب");
            if (viewZoneButton != null) viewZoneButton.text = AmanLocalization.Text("VIEW ZONE", "عرض المنطقة");
            primaryButton.text = incident.status == "awaiting_approval"
                ? AmanLocalization.Text("APPROVE DISPATCH", "الموافقة على الإرسال")
                : AmanLocalization.Text("RESOLVE INCIDENT", "إنهاء الحادث");
            root.style.display = DisplayStyle.Flex;
            UpdateButton();

            incidentZone = client.FindSceneZone(incident.zone_id);
            if (viewZoneButton != null) viewZoneButton.SetEnabled(incidentZone != null);
        }

        private void ViewIncidentZone()
        {
            if (incidentZone == null) return;
            ZoneManager.Instance?.SetZoneFromNavigation(incidentZone);
            AmanCameraController cameraController = FindAnyObjectByType<AmanCameraController>();
            if (client != null && cameraController != null && client.TryGetCrowdBounds(incident.zone_id, out Bounds crowdBounds))
            {
                cameraController.FrameBounds(crowdBounds.center, crowdBounds);
                return;
            }
            ZoneManager.Instance?.RequestZoneJump(incidentZone);
        }

        private void Submit()
        {
            if (client == null || incident == null || busy) return;
            if (incident.status == "awaiting_approval" && !routeReviewed.value)
            {
                feedback.text = AmanLocalization.Text("Review the route before approving.", "راجع المسار قبل الموافقة.");
                return;
            }
            busy = true;
            feedback.text = AmanLocalization.Text("Waiting for backend confirmation…", "بانتظار تأكيد الخادم…");
            UpdateButton();
            if (incident.status == "awaiting_approval")
            {
                string responderId = responderChoice != null && responderChoice.index >= 0 && responderChoice.index < candidates.Length
                    ? candidates[responderChoice.index].responderId
                    : null;
                client.ApproveIncident(incident.id, responderId, Completed);
            }
            else client.ResolveIncident(incident.id, Completed);
        }

        private void Completed(bool success, string message)
        {
            busy = false;
            feedback.text = success ? AmanLocalization.Text("Confirmed by backend", "تم التأكيد من الخادم") : message;
            UpdateButton();
        }

        private void OnConnectionChanged(string message, bool connected)
        {
            if (root != null) root.EnableInClassList("incident-action-bar--offline", !connected);
            UpdateButton();
        }

        private void UpdateButton()
        {
            if (primaryButton != null) primaryButton.SetEnabled(!busy && client != null && client.IsConnected);
        }

        private void Hide()
        {
            incident = null;
            incidentZone = null;
            candidates = Array.Empty<BackendCandidate>();
            if (root != null) root.style.display = DisplayStyle.None;
        }

        private void OnLanguageChanged()
        {
            if (lastSnapshot != null) Refresh(lastSnapshot);
        }
    }
}
