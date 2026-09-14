using System.Collections;
using System.Collections.Generic;
using UnityEngine;
using Aman.Zones;

namespace Aman.CameraControl
{
    [DefaultExecutionOrder(-30)]
    [RequireComponent(typeof(Camera))]
    public class AmanCameraController : MonoBehaviour
    {
        [Header("Target & Orientation")]
        [Tooltip("Current focus point around which the camera orbits and pans")]
        public Vector3 pivotPoint = Vector3.zero;

        [Tooltip("Pitch angle (degrees above ground)")]
        [Range(-89f, 89f)]
        public float pitch = 50f;

        [Tooltip("Yaw angle (rotation around vertical axis)")]
        public float yaw = 45f;

        [Tooltip("Distance from the pivot point")]
        public float distance = 40f;

        [Header("Zoom Limits")]
        public float minDistance = 5f;
        public float maxDistance = 250f;

        [Header("Free Navigation Settings")]
        [Tooltip("Base WASD fly speed")]
        public float keyPanSpeed = 35f;

        [Tooltip("Q/E and Space/Ctrl vertical fly speed")]
        public float keyVerticalSpeed = 25f;

        [Tooltip("Middle-mouse screen-space pan sensitivity")]
        public float mousePanSensitivity = 0.018f;

        [Tooltip("Mouse scroll zoom sensitivity")]
        public float zoomSensitivity = 15f;

        [Tooltip("Right-mouse freelook and Alt+left orbit sensitivity")]
        public float mouseRotateSensitivity = 3.5f;

        [Tooltip("Multiplier applied while Shift is held")]
        public float fastMoveMultiplier = 3.5f;

        [Tooltip("Acceleration and deceleration responsiveness")]
        [Range(1f, 30f)]
        public float movementSmoothing = 12f;

        [Tooltip("Mouse-look smoothing responsiveness")]
        [Range(1f, 40f)]
        public float lookSmoothing = 22f;

        [Header("Zone Detection on Free Movement")]
        [Tooltip("Automatically select zones as the operator free-moves over them")]
        public bool autoSelectZoneOnMove = true;

        [Tooltip("Small XZ allowance when deciding whether the camera is physically inside a zone")]
        [Range(0f, 2f)]
        public float physicalZoneMargin = 0.25f;

        [Tooltip("XZ padding used when testing which zone the center of the camera is looking at")]
        [Range(0f, 10f)]
        public float lookZonePadding = 1.5f;

        [Tooltip("Vertical half-height of zone look targets so elevated camera views still select correctly")]
        [Range(2f, 100f)]
        public float lookZoneVerticalExtent = 35f;

        [Tooltip("Maximum distance at which looking toward a zone can select it")]
        public float lookZoneMaxDistance = 600f;

        [Header("Transition Settings")]
        [Tooltip("Duration in seconds for zone-jump framing transitions")]
        [Range(0.2f, 1.0f)]
        public float transitionDuration = 0.45f;

        [Tooltip("Framing margin multiplier around zone bounding bounds")]
        public float framingMargin = 1.3f;

        [Header("Zone Viewpoint Safety")]
        [Tooltip("Geometry layers tested when validating an authored zone camera viewpoint")]
        public LayerMask viewpointOccluderLayers = ~((1 << 5) | (1 << 6));

        [Tooltip("Empty radius required around a zone camera position")]
        [Range(0.1f, 2f)]
        public float viewpointClearanceRadius = 0.6f;

        [Tooltip("Height above the zone used by automatically selected fallback viewpoints")]
        [Range(2f, 30f)]
        public float fallbackViewElevation = 8f;

        [Header("Hotkeys")]
        public KeyCode homeHotkey = KeyCode.H;

        [Header("Zone View Cutaway")]
        [Tooltip("Hide venue mesh sections that block the view between the camera and the selected zone")]
        public bool enableZoneCutaway = true;

        [Tooltip("Radius of the opening relative to the selected zone's half-diagonal")]
        [Range(0.25f, 1.5f)]
        public float cutawayCoverage = 1.05f;

        [Tooltip("Fallback distance in front of the target protected when the zone boundary cannot be resolved")]
        [Range(0.25f, 5f)]
        public float cutawayTargetProtection = 1.5f;

        [Tooltip("How far the opening may continue past the near edge of the selected zone")]
        [Range(0.5f, 10f)]
        public float cutawayZonePenetration = 3f;

        [Tooltip("Protect upward-facing surfaces such as floors; lower values protect steeper slopes")]
        [Range(0f, 1f)]
        public float cutawayFloorNormalThreshold = 0.55f;

        [Tooltip("Layers eligible to be hidden. UI and Selectable layers are excluded by default")]
        public LayerMask cutawayOccluderLayers = ~((1 << 5) | (1 << 6));

        [SerializeField, HideInInspector]
        private Shader zoneCutawayShader;

        private Camera cam;
        private ZoneManager subscribedZoneManager;
        private Coroutine activeTransition;
        private bool isTransitioning = false;
        private Vector2 lastMousePos;
        private Vector2 smoothedLookDelta;
        private Vector3 smoothedMoveVelocity;
        private float zoneCheckTimer = 0f;
        private float cutawayCandidateRefreshTimer;
        private bool warnedMissingCutawayShader;
        private readonly Dictionary<Renderer, Material[]> originalCutawayMaterials = new Dictionary<Renderer, Material[]>();
        private readonly Dictionary<Material, Material> cutawayMaterialCache = new Dictionary<Material, Material>();

        private static readonly int CutawayEnabled = Shader.PropertyToID("_AmanCutawayEnabled");
        private static readonly int CutawayCamera = Shader.PropertyToID("_AmanCutawayCamera");
        private static readonly int CutawayTarget = Shader.PropertyToID("_AmanCutawayTarget");
        private static readonly int CutawayNearRadius = Shader.PropertyToID("_AmanCutawayNearRadius");
        private static readonly int CutawayTargetRadius = Shader.PropertyToID("_AmanCutawayTargetRadius");
        private static readonly int CutawayNearPadding = Shader.PropertyToID("_AmanCutawayNearPadding");
        private static readonly int CutawayTargetPadding = Shader.PropertyToID("_AmanCutawayTargetPadding");
        private static readonly int CutawayFloorNormalThreshold = Shader.PropertyToID("_AmanCutawayFloorNormalThreshold");

        private void Awake()
        {
            cam = GetComponent<Camera>();
        }

        private void OnEnable()
        {
            SubscribeToZoneManager();
        }

        private void Start()
        {
            if (pivotPoint == Vector3.zero)
            {
                Plane ground = new Plane(Vector3.up, Vector3.zero);
                Ray ray = new Ray(transform.position, transform.forward);
                if (ground.Raycast(ray, out float enter))
                {
                    pivotPoint = ray.GetPoint(enter);
                    distance = Vector3.Distance(transform.position, pivotPoint);
                }
                else
                {
                    pivotPoint = transform.position + transform.forward * distance;
                }
            }

            Vector3 euler = transform.rotation.eulerAngles;
            pitch = euler.x;
            yaw = euler.y;

            SubscribeToZoneManager();

            ApplyCameraTransform();
        }

        private void OnDestroy()
        {
            RestoreCutawayMaterials();
            foreach (Material material in cutawayMaterialCache.Values)
            {
                if (material != null) Destroy(material);
            }
            cutawayMaterialCache.Clear();
            UnsubscribeFromZoneManager();
        }

        private void OnDisable()
        {
            UnsubscribeFromZoneManager();
            RestoreCutawayMaterials();
        }

        private void SubscribeToZoneManager()
        {
            ZoneManager manager = ZoneManager.Instance;
            if (manager == null || subscribedZoneManager == manager) return;

            UnsubscribeFromZoneManager();
            subscribedZoneManager = manager;
            subscribedZoneManager.OnZoneJumpRequested += OnZoneJumpRequested;
            subscribedZoneManager.OnHomeJumpRequested += OnHomeJumpRequested;
        }

        private void UnsubscribeFromZoneManager()
        {
            if (subscribedZoneManager == null) return;
            subscribedZoneManager.OnZoneJumpRequested -= OnZoneJumpRequested;
            subscribedZoneManager.OnHomeJumpRequested -= OnHomeJumpRequested;
            subscribedZoneManager = null;
        }

        private void Update()
        {
            HandleHotkeys();
            HandleFreeNavigation();
            ApplyCameraTransform();

            if (autoSelectZoneOnMove && !isTransitioning)
            {
                CheckProximityZoneSelection();
            }
        }

        private void LateUpdate()
        {
            UpdateZoneCutaway();
        }

        private void UpdateZoneCutaway()
        {
            Zone zone = ZoneManager.Instance != null ? ZoneManager.Instance.CurrentZone : null;
            if (!enableZoneCutaway || zone == null)
            {
                RestoreCutawayMaterials();
                return;
            }

            cutawayCandidateRefreshTimer -= Time.unscaledDeltaTime;
            if (originalCutawayMaterials.Count == 0 || cutawayCandidateRefreshTimer <= 0f)
            {
                ApplyCutawayMaterials();
                cutawayCandidateRefreshTimer = 2f;
            }

            Bounds bounds = zone.WorldBounds;
            float targetY = Mathf.Clamp(Mathf.Max(zone.FocusPoint.y, bounds.min.y + 1.5f), bounds.min.y, bounds.max.y);
            Vector3 center = new Vector3(zone.FocusPoint.x, targetY, zone.FocusPoint.z);
            float targetRadius = Mathf.Sqrt(bounds.extents.x * bounds.extents.x
                + bounds.extents.z * bounds.extents.z) * cutawayCoverage;
            Vector3 cameraToTarget = center - transform.position;
            float cameraToTargetDistance = cameraToTarget.magnitude;
            float targetPadding = cutawayTargetProtection;
            if (cameraToTargetDistance > 0.001f)
            {
                Ray zoneRay = new Ray(transform.position, cameraToTarget / cameraToTargetDistance);
                if (bounds.IntersectRay(zoneRay, out float zoneEntryDistance)
                    && zoneEntryDistance < cameraToTargetDistance)
                {
                    float cutawayEndDistance = Mathf.Min(cameraToTargetDistance - 0.1f,
                        zoneEntryDistance + Mathf.Max(0.5f, cutawayZonePenetration));
                    targetPadding = Mathf.Max(0.1f, cameraToTargetDistance - cutawayEndDistance);
                }
            }

            Shader.SetGlobalFloat(CutawayEnabled, 1f);
            Shader.SetGlobalVector(CutawayCamera, transform.position);
            Shader.SetGlobalVector(CutawayTarget, center);
            Shader.SetGlobalFloat(CutawayNearRadius, Mathf.Max(0.75f, targetRadius * 0.12f));
            Shader.SetGlobalFloat(CutawayTargetRadius, Mathf.Max(1f, targetRadius));
            Shader.SetGlobalFloat(CutawayNearPadding, cam.nearClipPlane + 0.1f);
            Shader.SetGlobalFloat(CutawayTargetPadding, targetPadding);
            Shader.SetGlobalFloat(CutawayFloorNormalThreshold, cutawayFloorNormalThreshold);
        }

        private bool IsEligibleCutawayRenderer(Renderer candidate)
        {
            if (candidate == null || !candidate.enabled || candidate is not MeshRenderer) return false;
            if ((cutawayOccluderLayers.value & (1 << candidate.gameObject.layer)) == 0) return false;
            if (candidate.transform.IsChildOf(transform)) return false;
            if (candidate.name.StartsWith("RiskOverlay_", System.StringComparison.Ordinal)) return false;
            if (candidate.GetComponentInParent<Aman.Simulation.SelectableEntity>() != null) return false;
            return true;
        }

        private void ApplyCutawayMaterials()
        {
            if (zoneCutawayShader == null) zoneCutawayShader = Shader.Find("AMAN/ZoneCutawayLit");
            if (zoneCutawayShader == null)
            {
                if (!warnedMissingCutawayShader)
                {
                    Debug.LogWarning("AMAN zone cutaway shader could not be loaded; venue geometry will remain unchanged.");
                    warnedMissingCutawayShader = true;
                }
                return;
            }

            foreach (Renderer renderer in FindObjectsByType<Renderer>(FindObjectsInactive.Exclude))
            {
                if (!IsEligibleCutawayRenderer(renderer) || originalCutawayMaterials.ContainsKey(renderer)) continue;
                Material[] originals = renderer.sharedMaterials;
                Material[] replacements = new Material[originals.Length];
                bool replacedAny = false;
                for (int i = 0; i < originals.Length; i++)
                {
                    Material original = originals[i];
                    if (!IsOpaqueCutawayMaterial(original))
                    {
                        replacements[i] = original;
                        continue;
                    }

                    if (!cutawayMaterialCache.TryGetValue(original, out Material replacement) || replacement == null)
                    {
                        replacement = new Material(zoneCutawayShader)
                        {
                            name = original.name + " (AMAN Cutaway)",
                            renderQueue = original.renderQueue,
                            enableInstancing = original.enableInstancing
                        };
                        replacement.CopyPropertiesFromMaterial(original);
                        cutawayMaterialCache[original] = replacement;
                    }
                    replacements[i] = replacement;
                    replacedAny = true;
                }

                if (!replacedAny) continue;
                originalCutawayMaterials[renderer] = originals;
                renderer.sharedMaterials = replacements;
            }
        }

        private static bool IsOpaqueCutawayMaterial(Material material)
        {
            if (material == null || material.renderQueue >= 3000) return false;
            return !material.HasProperty("_Surface") || material.GetFloat("_Surface") < 0.5f;
        }

        private void RestoreCutawayMaterials()
        {
            Shader.SetGlobalFloat(CutawayEnabled, 0f);
            if (originalCutawayMaterials.Count == 0) return;
            foreach (KeyValuePair<Renderer, Material[]> item in originalCutawayMaterials)
            {
                if (item.Key != null) item.Key.sharedMaterials = item.Value;
            }
            originalCutawayMaterials.Clear();
        }

        private void HandleHotkeys()
        {
            if (Input.GetKeyDown(homeHotkey) || Input.GetKeyDown(KeyCode.Home))
            {
                if (ZoneManager.Instance != null)
                {
                    ZoneManager.Instance.RequestHomeJump();
                }
                else
                {
                    FrameHome();
                }
            }

            if (Input.GetKeyDown(KeyCode.F) && ZoneManager.Instance?.CurrentZone != null)
            {
                OnZoneJumpRequested(ZoneManager.Instance.CurrentZone);
            }
        }

        private void HandleFreeNavigation()
        {
            float deltaTime = Mathf.Max(Time.unscaledDeltaTime, 0.0001f);
            float h = Input.GetAxisRaw("Horizontal");
            float v = Input.GetAxisRaw("Vertical");
            float vert = 0f;
            if (Input.GetKey(KeyCode.E) || Input.GetKey(KeyCode.Space) || Input.GetKey(KeyCode.R)) vert += 1f;
            if (Input.GetKey(KeyCode.Q) || Input.GetKey(KeyCode.C) || Input.GetKey(KeyCode.LeftControl)) vert -= 1f;

            bool rightLook = Input.GetMouseButton(1);
            bool middlePan = Input.GetMouseButton(2);
            bool altOrbit = (Input.GetKey(KeyCode.LeftAlt) || Input.GetKey(KeyCode.RightAlt)) && Input.GetMouseButton(0);
            bool mouseNavigation = rightLook || middlePan || altOrbit;
            if (Input.GetMouseButtonDown(1) || Input.GetMouseButtonDown(2)
                || ((Input.GetKey(KeyCode.LeftAlt) || Input.GetKey(KeyCode.RightAlt)) && Input.GetMouseButtonDown(0)))
            {
                lastMousePos = Input.mousePosition;
            }

            Vector2 rawMouseDelta = Vector2.zero;
            if (mouseNavigation)
            {
                Vector2 currentMouse = Input.mousePosition;
                rawMouseDelta = currentMouse - lastMousePos;
                lastMousePos = currentMouse;
            }

            float scroll = Input.GetAxis("Mouse ScrollWheel");
            bool hasUserInput = Mathf.Abs(h) > 0.01f || Mathf.Abs(v) > 0.01f || Mathf.Abs(vert) > 0.01f
                || rawMouseDelta.sqrMagnitude > 0.01f || Mathf.Abs(scroll) > 0.001f;
            if (hasUserInput && isTransitioning) StopTransition();
            if (isTransitioning)
            {
                smoothedMoveVelocity = Vector3.zero;
                smoothedLookDelta = Vector2.zero;
                return;
            }

            Vector3 cameraPositionBeforeLook = transform.position;
            if (rightLook || altOrbit)
            {
                float lookBlend = 1f - Mathf.Exp(-lookSmoothing * deltaTime);
                smoothedLookDelta = Vector2.Lerp(smoothedLookDelta, rawMouseDelta, lookBlend);
                yaw = (yaw + smoothedLookDelta.x * mouseRotateSensitivity * 0.1f) % 360f;
                pitch = Mathf.Clamp(pitch - smoothedLookDelta.y * mouseRotateSensitivity * 0.1f, -89f, 89f);

                if (rightLook && !altOrbit)
                {
                    Quaternion lookRotation = Quaternion.Euler(pitch, yaw, 0f);
                    pivotPoint = cameraPositionBeforeLook + lookRotation * Vector3.forward * distance;
                }
            }
            else
            {
                smoothedLookDelta = Vector2.zero;
            }

            Quaternion navigationRotation = Quaternion.Euler(pitch, yaw, 0f);
            Vector3 desiredVelocity = navigationRotation * Vector3.right * h
                + navigationRotation * Vector3.forward * v;
            if (desiredVelocity.sqrMagnitude > 1f) desiredVelocity.Normalize();
            desiredVelocity *= keyPanSpeed;
            desiredVelocity += Vector3.up * (vert * keyVerticalSpeed);
            if (Input.GetKey(KeyCode.LeftShift) || Input.GetKey(KeyCode.RightShift))
                desiredVelocity *= Mathf.Max(1f, fastMoveMultiplier);

            float movementBlend = 1f - Mathf.Exp(-movementSmoothing * deltaTime);
            smoothedMoveVelocity = Vector3.Lerp(smoothedMoveVelocity, desiredVelocity, movementBlend);
            if (desiredVelocity.sqrMagnitude < 0.001f && smoothedMoveVelocity.sqrMagnitude < 0.001f)
                smoothedMoveVelocity = Vector3.zero;

            Vector3 translation = smoothedMoveVelocity * deltaTime;
            if (middlePan)
            {
                float panFactor = mousePanSensitivity * Mathf.Max(0.2f, distance / 30f);
                translation -= navigationRotation * Vector3.right * (rawMouseDelta.x * panFactor);
                translation -= navigationRotation * Vector3.up * (rawMouseDelta.y * panFactor);
            }
            if (Mathf.Abs(scroll) > 0.001f)
            {
                float dollyDistance = scroll * zoomSensitivity * Mathf.Max(0.25f, distance / 20f);
                translation += navigationRotation * Vector3.forward * dollyDistance;
            }

            pivotPoint += translation;
        }

        private void CheckProximityZoneSelection()
        {
            zoneCheckTimer += Time.unscaledDeltaTime;
            if (zoneCheckTimer < 0.05f) return;
            zoneCheckTimer = 0f;

            if (ZoneManager.Instance == null) return;

            Zone detected = FindZoneContainingCamera();
            if (detected == null) detected = FindZoneInCenterView();
            if (detected != ZoneManager.Instance.CurrentZone)
            {
                ZoneManager.Instance.SetZoneFromNavigation(detected);
            }
        }

        private Zone FindZoneContainingCamera()
        {
            Zone best = null;
            float bestDistanceSquared = float.MaxValue;
            Vector3 cameraPosition = transform.position;
            foreach (Zone zone in ZoneManager.Instance.GetZones())
            {
                if (zone == null) continue;
                Bounds bounds = zone.WorldBounds;
                float margin = Mathf.Max(0f, physicalZoneMargin);
                bool insideX = cameraPosition.x >= bounds.min.x - margin && cameraPosition.x <= bounds.max.x + margin;
                bool insideZ = cameraPosition.z >= bounds.min.z - margin && cameraPosition.z <= bounds.max.z + margin;
                if (!insideX || !insideZ) continue;

                Vector2 offset = new Vector2(cameraPosition.x - bounds.center.x, cameraPosition.z - bounds.center.z);
                if (offset.sqrMagnitude >= bestDistanceSquared) continue;
                bestDistanceSquared = offset.sqrMagnitude;
                best = zone;
            }
            return best;
        }

        private Zone FindZoneInCenterView()
        {
            Zone best = null;
            float nearestHit = Mathf.Max(0f, lookZoneMaxDistance);
            Ray lookRay = new Ray(transform.position, transform.forward);
            foreach (Zone zone in ZoneManager.Instance.GetZones())
            {
                if (zone == null) continue;
                Bounds source = zone.WorldBounds;
                float padding = Mathf.Max(0f, lookZonePadding);
                Bounds lookTarget = new Bounds(
                    source.center,
                    new Vector3(source.size.x + padding * 2f,
                        Mathf.Max(source.size.y, lookZoneVerticalExtent * 2f),
                        source.size.z + padding * 2f));
                if (!lookTarget.IntersectRay(lookRay, out float hitDistance)) continue;
                if (hitDistance < 0f || hitDistance > nearestHit) continue;
                nearestHit = hitDistance;
                best = zone;
            }
            return best;
        }

        private void ApplyCameraTransform()
        {
            Quaternion rot = Quaternion.Euler(pitch, yaw, 0f);
            Vector3 camPos = pivotPoint - (rot * Vector3.forward * distance);

            transform.rotation = rot;
            transform.position = camPos;
        }

        private void OnZoneJumpRequested(Zone zone)
        {
            if (zone == null) return;
            Debug.Log($"[AmanCameraController] Framing zone: {zone.displayName}");
            FrameZone(zone);
        }

        public void FrameZone(Zone zone)
        {
            if (zone == null) return;

            Bounds bounds = zone.WorldBounds;
            Vector3 lookTarget = zone.FocusPoint;
            lookTarget.y = Mathf.Max(lookTarget.y, bounds.min.y + 1.5f);
            Vector3 viewpoint = FindClearZoneViewpoint(zone.CameraViewPosition, lookTarget, bounds, out bool usedFallback);
            if (usedFallback)
            {
                Debug.LogWarning($"[AmanCameraController] The authored viewpoint for {zone.displayName} was obstructed; using an elevated fallback.");
            }
            StartTransitionToPose(viewpoint, lookTarget);
        }

        private Vector3 FindClearZoneViewpoint(Vector3 authored, Vector3 target, Bounds bounds, out bool usedFallback)
        {
            if (HasClearView(authored, target))
            {
                usedFallback = false;
                return authored;
            }

            Vector3 authoredDirection = authored - target;
            authoredDirection.y = 0f;
            if (authoredDirection.sqrMagnitude < 0.01f) authoredDirection = Vector3.back;
            authoredDirection.Normalize();

            float horizontalDistance = Mathf.Clamp(Mathf.Min(bounds.extents.x, bounds.extents.z) * 0.65f, 6f, 25f);
            float height = bounds.max.y + Mathf.Max(2f, fallbackViewElevation);
            for (int quarterTurn = 0; quarterTurn < 4; quarterTurn++)
            {
                Vector3 direction = Quaternion.Euler(0f, quarterTurn * 90f, 0f) * authoredDirection;
                Vector3 candidate = new Vector3(
                    target.x + direction.x * horizontalDistance,
                    height,
                    target.z + direction.z * horizontalDistance);
                if (HasClearView(candidate, target))
                {
                    usedFallback = true;
                    return candidate;
                }
            }

            usedFallback = true;
            return new Vector3(target.x, height + fallbackViewElevation, target.z);
        }

        private bool HasClearView(Vector3 viewpoint, Vector3 target)
        {
            if (Physics.CheckSphere(viewpoint, Mathf.Max(0.1f, viewpointClearanceRadius),
                    viewpointOccluderLayers, QueryTriggerInteraction.Ignore)) return false;

            Vector3 direction = target - viewpoint;
            float distanceToTarget = direction.magnitude;
            if (distanceToTarget < 0.1f) return false;
            return !Physics.Raycast(viewpoint, direction / distanceToTarget,
                Mathf.Max(0f, distanceToTarget - 0.25f), viewpointOccluderLayers, QueryTriggerInteraction.Ignore);
        }

        private void StartTransitionToPose(Vector3 cameraPosition, Vector3 lookTarget)
        {
            Vector3 forward = lookTarget - cameraPosition;
            float targetDistance = Mathf.Clamp(forward.magnitude, minDistance, maxDistance);
            if (forward.sqrMagnitude < 0.01f) forward = transform.forward;
            Quaternion targetRotation = Quaternion.LookRotation(forward.normalized, Vector3.up);
            Vector3 euler = targetRotation.eulerAngles;
            float targetPitch = euler.x > 180f ? euler.x - 360f : euler.x;
            StartTransition(lookTarget, targetDistance, Mathf.Clamp(targetPitch, -89f, 89f), euler.y);
        }

        private void OnHomeJumpRequested()
        {
            FrameHome();
        }

        public void FrameHome()
        {
            Bounds bounds;
            if (ZoneManager.Instance != null)
            {
                bounds = ZoneManager.Instance.GetVenueBounds();
            }
            else
            {
                bounds = new Bounds(pivotPoint, new Vector3(80f, 15f, 80f));
            }

            FrameBounds(bounds.center, bounds);
        }

        public void FrameBounds(Vector3 targetFocus, Bounds bounds)
        {
            float targetDistance = CalculateFramingDistance(bounds);
            StartTransition(targetFocus, targetDistance, pitch, yaw);
        }

        private float CalculateFramingDistance(Bounds bounds)
        {
            float fovRad = cam.fieldOfView * Mathf.Deg2Rad;
            float aspect = cam.aspect;

            float horizontalFovRad = 2f * Mathf.Atan(Mathf.Tan(fovRad * 0.5f) * aspect);

            float sizeX = bounds.size.x;
            float sizeY = bounds.size.y;
            float sizeZ = bounds.size.z;

            float radius = Mathf.Max(sizeX, sizeZ, sizeY) * 0.5f;
            radius *= framingMargin;

            float distV = radius / Mathf.Sin(fovRad * 0.5f);
            float distH = radius / Mathf.Sin(horizontalFovRad * 0.5f);

            float calculated = Mathf.Max(distV, distH);
            return Mathf.Clamp(calculated, minDistance, maxDistance);
        }

        private void StartTransition(Vector3 targetPivot, float targetDist, float targetPitch, float targetYaw)
        {
            if (activeTransition != null)
            {
                StopCoroutine(activeTransition);
            }
            activeTransition = StartCoroutine(TransitionRoutine(targetPivot, targetDist, targetPitch, targetYaw));
        }

        private void StopTransition()
        {
            if (activeTransition != null)
            {
                StopCoroutine(activeTransition);
                activeTransition = null;
            }
            isTransitioning = false;
        }

        private IEnumerator TransitionRoutine(Vector3 targetPivot, float targetDist, float targetPitch, float targetYaw)
        {
            isTransitioning = true;

            Vector3 startPivot = pivotPoint;
            float startDist = distance;
            float startPitch = pitch;
            float startYaw = yaw;

            float deltaYaw = Mathf.DeltaAngle(startYaw, targetYaw);

            float elapsed = 0f;
            while (elapsed < transitionDuration)
            {
                elapsed += Time.deltaTime;
                float t = Mathf.Clamp01(elapsed / transitionDuration);
                float smoothT = 1f - Mathf.Pow(1f - t, 3f);

                pivotPoint = Vector3.Lerp(startPivot, targetPivot, smoothT);
                distance = Mathf.Lerp(startDist, targetDist, smoothT);
                pitch = Mathf.Lerp(startPitch, targetPitch, smoothT);
                yaw = startYaw + deltaYaw * smoothT;

                yield return null;
            }

            pivotPoint = targetPivot;
            distance = targetDist;
            pitch = targetPitch;
            yaw = targetYaw;

            isTransitioning = false;
            activeTransition = null;
        }
    }
}
