using System;
using UnityEngine;

namespace Aman.Zones
{
    [SelectionBase]
    [DisallowMultipleComponent]
    public class Zone : MonoBehaviour
    {
        [Header("Zone Identification")]
        [Tooltip("Stable identifier used by backend and simulation (e.g. 'north-concourse')")]
        public string id = "";

        [Tooltip("Human-readable label shown in UI and top bar (e.g. 'North Concourse')")]
        public string displayName = "New Zone";

        [Tooltip("Order index for sorting zone navigation buttons in the UI")]
        public int order = 0;

        [Header("Spatial Definition")]
        [Tooltip("Local center offset for the bounding volume")]
        public Vector3 boundsCenter = Vector3.zero;

        [Tooltip("Dimensions of the bounding volume")]
        public Vector3 boundsSize = new Vector3(25f, 6f, 25f);

        [Header("Camera Focus")]
        [Tooltip("Local offset relative to this GameObject where the camera should focus")]
        public Vector3 focusOffset = Vector3.zero;

        [Tooltip("Local camera position used when this zone is explicitly focused")]
        public Vector3 cameraViewOffset = new Vector3(0f, 14f, -18f);

        [Header("Gizmo Styling")]
        public Color gizmoColor = new Color(0.12f, 0.75f, 1f, 0.25f);

        public Vector3 FocusPoint
        {
            get => transform.TransformPoint(focusOffset);
            set => focusOffset = transform.InverseTransformPoint(value);
        }

        public Vector3 CameraViewPosition
        {
            get => transform.TransformPoint(cameraViewOffset);
            set => cameraViewOffset = transform.InverseTransformPoint(value);
        }

        public Bounds WorldBounds
        {
            get
            {
                Vector3 worldCenter = transform.TransformPoint(boundsCenter);
                Vector3 worldSize = Vector3.Scale(boundsSize, transform.lossyScale);
                return new Bounds(worldCenter, worldSize);
            }
        }

        private void Reset()
        {
            if (string.IsNullOrEmpty(id))
            {
                id = "zone-" + Guid.NewGuid().ToString().Substring(0, 8);
            }
            if (string.IsNullOrEmpty(displayName))
            {
                displayName = gameObject.name;
            }
        }

        private void OnValidate()
        {
            if (string.IsNullOrEmpty(id))
            {
                id = "zone-" + Guid.NewGuid().ToString().Substring(0, 8);
            }
        }

        private void OnDrawGizmos()
        {
            DrawZoneGizmo(false);
        }

        private void OnDrawGizmosSelected()
        {
            DrawZoneGizmo(true);
        }

        private void DrawZoneGizmo(bool isSelected)
        {
            Matrix4x4 prevMatrix = Gizmos.matrix;
            Color prevColor = Gizmos.color;

            Gizmos.matrix = Matrix4x4.TRS(transform.position, transform.rotation, transform.lossyScale);

            Color fill = gizmoColor;
            fill.a = isSelected ? 0.35f : 0.15f;
            Gizmos.color = fill;
            Gizmos.DrawCube(boundsCenter, boundsSize);

            Color border = gizmoColor;
            border.a = isSelected ? 1f : 0.7f;
            Gizmos.color = border;
            Gizmos.DrawWireCube(boundsCenter, boundsSize);

            Gizmos.matrix = prevMatrix;
            Vector3 worldFocus = FocusPoint;
            Gizmos.color = isSelected ? Color.yellow : Color.white;
            Gizmos.DrawSphere(worldFocus, isSelected ? 0.8f : 0.5f);
            Gizmos.DrawLine(transform.position, worldFocus);

            Vector3 worldView = CameraViewPosition;
            Gizmos.color = isSelected ? Color.cyan : new Color(0.2f, 0.9f, 1f, 0.8f);
            Gizmos.DrawWireSphere(worldView, isSelected ? 1f : 0.65f);
            Gizmos.DrawLine(worldView, worldFocus);

            Gizmos.color = prevColor;
        }
    }
}
