using UnityEngine;
using UnityEngine.UIElements;
using Aman.Data;
using Aman.Models;

namespace Aman.Simulation
{
    public class SelectionManager : MonoBehaviour
    {
        private IDataSource dataSource;
        private SelectableEntity currentSelection;
        private SelectionDetail currentDetails;

        private VisualElement selectionCard;
        private VisualElement detailsContainer;
        private Label selectionTitle;

        private void Start()
        {
            Aman.UI.AmanLocalization.LanguageChanged += OnLanguageChanged;
            dataSource = FindAnyObjectByType<RemoteAmanClient>();
            if (dataSource == null)
            {
                Debug.LogWarning("SelectionManager: No AMAN IDataSource found in scene.");
            }
        }

        private void OnDestroy()
        {
            Aman.UI.AmanLocalization.LanguageChanged -= OnLanguageChanged;
        }

        private void Update()
        {
            if (Input.GetKeyDown(KeyCode.Escape))
            {
                ClearSelection();
                return;
            }

            if (Input.GetMouseButtonDown(0))
            {
                if (Camera.main == null) return;
                Ray ray = Camera.main.ScreenPointToRay(Input.mousePosition);
                if (Physics.Raycast(ray, out RaycastHit hit, Mathf.Infinity, LayerMask.GetMask("Selectable"), QueryTriggerInteraction.Collide))
                {
                    SelectableEntity entity = hit.collider.GetComponentInParent<SelectableEntity>();
                    if (entity != null)
                    {
                        SelectEntity(entity);
                    }
                    else
                    {
                        ClearSelection();
                    }
                }
                else
                {
                    ClearSelection();
                }
            }
        }

        private void SelectEntity(SelectableEntity entity)
        {
            if (currentSelection != null)
            {
                currentSelection.SetHighlight(false);
            }

            currentSelection = entity;
            currentSelection.SetHighlight(true);

            if (dataSource != null)
            {
                currentDetails = dataSource.GetSelectionDetail(entity.Id, entity.EntityType);
            }

            UpdateSelectionCard();
        }

        private void ClearSelection()
        {
            if (currentSelection != null)
            {
                currentSelection.SetHighlight(false);
                currentSelection = null;
                currentDetails = null;
            }

            HideSelectionCard();
        }

        private void EnsureSelectionCard()
        {
            if (selectionCard != null) return;

            UIDocument uiDoc = FindAnyObjectByType<UIDocument>();
            if (uiDoc == null) return;

            selectionCard = uiDoc.rootVisualElement.Q("selection-card");
            detailsContainer = uiDoc.rootVisualElement.Q("selection-card-details");
            selectionTitle = uiDoc.rootVisualElement.Q<Label>("selection-card-title");
            if (selectionTitle != null)
                selectionTitle.text = Aman.UI.AmanLocalization.Text("Entity Details", "تفاصيل العنصر");
        }

        private void UpdateSelectionCard()
        {
            EnsureSelectionCard();
            if (selectionCard == null || detailsContainer == null || currentDetails == null) return;

            selectionCard.style.display = DisplayStyle.Flex;
            detailsContainer.Clear();

            if (currentDetails.EntityType == "responder")
            {
                if (!string.IsNullOrWhiteSpace(currentDetails.DisplayName))
                {
                    AddDetailRow(Aman.UI.AmanLocalization.Text("Name", "الاسم"), currentDetails.DisplayName);
                }
                AddDetailRow(Aman.UI.AmanLocalization.Text("Callsign/ID", "رمز النداء/المعرّف"), currentDetails.Id);
                AddDetailRow(Aman.UI.AmanLocalization.Text("Role", "الدور"), Aman.UI.AmanLocalization.Text("RESPONDER", "مستجيب"));
                AddDetailRow(Aman.UI.AmanLocalization.Text("Status", "الحالة"), Aman.UI.AmanLocalization.Status(currentDetails.Status));
                AddDetailRow(Aman.UI.AmanLocalization.Text("Zone", "المنطقة"), Aman.UI.AmanLocalization.Zone(currentDetails.ZoneId));
                AddDetailRow(Aman.UI.AmanLocalization.Text("Reachable", "يمكن الاتصال"), Aman.UI.AmanLocalization.Text(currentDetails.IsReachable.ToString(), currentDetails.IsReachable ? "نعم" : "لا"));
            }
            else
            {
                AddDetailRow(Aman.UI.AmanLocalization.Text("Anon Ref", "مرجع مجهول"), currentDetails.Id);
                AddDetailRow(Aman.UI.AmanLocalization.Text("Role", "الدور"), Aman.UI.AmanLocalization.Text("CROWD", "حشد"));
                AddDetailRow(Aman.UI.AmanLocalization.Text("Zone", "المنطقة"), Aman.UI.AmanLocalization.Zone(currentDetails.ZoneId));
                AddDetailRow(Aman.UI.AmanLocalization.Text("Reachable", "يمكن الاتصال"), Aman.UI.AmanLocalization.Text(currentDetails.IsReachable.ToString(), currentDetails.IsReachable ? "نعم" : "لا"));
            }
        }

        private void OnLanguageChanged()
        {
            EnsureSelectionCard();
            if (selectionTitle != null)
                selectionTitle.text = Aman.UI.AmanLocalization.Text("Entity Details", "تفاصيل العنصر");
            if (currentDetails != null) UpdateSelectionCard();
        }

        private void AddDetailRow(string key, string value)
        {
            var row = new Label($"{key}: {value}");
            row.AddToClassList("selection-card__row");
            detailsContainer.Add(row);
        }

        private void HideSelectionCard()
        {
            EnsureSelectionCard();
            if (selectionCard != null)
            {
                selectionCard.style.display = DisplayStyle.None;
            }
        }
    }
}
