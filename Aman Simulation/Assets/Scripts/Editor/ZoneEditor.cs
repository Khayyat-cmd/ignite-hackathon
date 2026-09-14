using UnityEditor;
using UnityEngine;
using Aman.Zones;

namespace Aman.Editor
{
    [CustomEditor(typeof(Zone))]
    [CanEditMultipleObjects]
    public class ZoneEditor : UnityEditor.Editor
    {
        private SerializedProperty idProp;
        private SerializedProperty displayNameProp;
        private SerializedProperty orderProp;
        private SerializedProperty boundsCenterProp;
        private SerializedProperty boundsSizeProp;
        private SerializedProperty focusOffsetProp;
        private SerializedProperty cameraViewOffsetProp;
        private SerializedProperty gizmoColorProp;

        private void OnEnable()
        {
            idProp = serializedObject.FindProperty("id");
            displayNameProp = serializedObject.FindProperty("displayName");
            orderProp = serializedObject.FindProperty("order");
            boundsCenterProp = serializedObject.FindProperty("boundsCenter");
            boundsSizeProp = serializedObject.FindProperty("boundsSize");
            focusOffsetProp = serializedObject.FindProperty("focusOffset");
            cameraViewOffsetProp = serializedObject.FindProperty("cameraViewOffset");
            gizmoColorProp = serializedObject.FindProperty("gizmoColor");
        }

        public override void OnInspectorGUI()
        {
            serializedObject.Update();

            Zone zone = (Zone)target;

            EditorGUILayout.Space(4);
            EditorGUILayout.LabelField("Zone Identification", EditorStyles.boldLabel);
            EditorGUILayout.PropertyField(idProp);
            EditorGUILayout.PropertyField(displayNameProp);
            EditorGUILayout.PropertyField(orderProp);

            EditorGUILayout.Space(6);
            EditorGUILayout.LabelField("Spatial Bounds", EditorStyles.boldLabel);
            EditorGUILayout.PropertyField(boundsCenterProp);
            EditorGUILayout.PropertyField(boundsSizeProp);

            EditorGUILayout.Space(6);
            EditorGUILayout.LabelField("Camera Focus", EditorStyles.boldLabel);
            EditorGUILayout.PropertyField(focusOffsetProp);
            EditorGUILayout.PropertyField(cameraViewOffsetProp);

            Vector3 worldFocus = zone.FocusPoint;
            Vector3 worldView = zone.CameraViewPosition;
            EditorGUILayout.LabelField($"World Focus Point", $"{worldFocus.x:F1}, {worldFocus.y:F1}, {worldFocus.z:F1}", EditorStyles.miniLabel);
            EditorGUILayout.LabelField($"World Camera View", $"{worldView.x:F1}, {worldView.y:F1}, {worldView.z:F1}", EditorStyles.miniLabel);

            EditorGUILayout.Space(6);
            EditorGUILayout.LabelField("Gizmo Styling", EditorStyles.boldLabel);
            EditorGUILayout.PropertyField(gizmoColorProp);

            serializedObject.ApplyModifiedProperties();

            EditorGUILayout.Space(10);
            EditorGUILayout.LabelField("Quick Actions", EditorStyles.boldLabel);

            EditorGUILayout.BeginHorizontal();
            if (GUILayout.Button("Snap Focus to Center"))
            {
                Undo.RecordObject(zone, "Snap Focus to Center");
                zone.focusOffset = zone.boundsCenter;
                EditorUtility.SetDirty(zone);
            }

            if (GUILayout.Button("Align Focus to Scene Cam"))
            {
                if (SceneView.lastActiveSceneView != null)
                {
                    Undo.RecordObject(zone, "Set Focus to Scene Camera");
                    zone.FocusPoint = SceneView.lastActiveSceneView.pivot;
                    EditorUtility.SetDirty(zone);
                }
            }
            EditorGUILayout.EndHorizontal();

            if (GUILayout.Button("Set Camera View from Scene Camera"))
            {
                if (SceneView.lastActiveSceneView != null)
                {
                    Undo.RecordObject(zone, "Set Zone Camera View");
                    zone.CameraViewPosition = SceneView.lastActiveSceneView.camera.transform.position;
                    EditorUtility.SetDirty(zone);
                }
            }

            if (GUILayout.Button("Frame in Scene View"))
            {
                if (SceneView.lastActiveSceneView != null)
                {
                    SceneView.lastActiveSceneView.Frame(zone.WorldBounds, false);
                }
            }

        }

        private void OnSceneGUI()
        {
            Zone zone = (Zone)target;
            if (zone == null) return;

            Vector3 worldFocus = zone.FocusPoint;
            EditorGUI.BeginChangeCheck();
            Vector3 newFocus = Handles.PositionHandle(worldFocus, Quaternion.identity);
            if (EditorGUI.EndChangeCheck())
            {
                Undo.RecordObject(zone, "Move Zone Focus Point");
                zone.FocusPoint = newFocus;
                EditorUtility.SetDirty(zone);
            }

            Handles.Label(worldFocus + Vector3.up * 0.5f, $"{zone.displayName} Focus", EditorStyles.boldLabel);

            Vector3 worldView = zone.CameraViewPosition;
            EditorGUI.BeginChangeCheck();
            Vector3 newView = Handles.PositionHandle(worldView, Quaternion.identity);
            if (EditorGUI.EndChangeCheck())
            {
                Undo.RecordObject(zone, "Move Zone Camera View");
                zone.CameraViewPosition = newView;
                EditorUtility.SetDirty(zone);
            }
            Handles.color = Color.cyan;
            Handles.DrawDottedLine(worldView, worldFocus, 4f);
            Handles.Label(worldView + Vector3.up * 0.5f, $"{zone.displayName} Camera", EditorStyles.boldLabel);
            Handles.color = Color.white;
        }
    }
}
