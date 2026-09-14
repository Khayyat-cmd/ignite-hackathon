using System;
using System.Collections;
using System.Collections.Generic;
using System.Globalization;
using System.Linq;
using Aman.Data;
using Aman.Models;
using Aman.Zones;
using UnityEngine;
using UnityEngine.AI;
using UnityEngine.Networking;

namespace Aman.Simulation
{
    [DefaultExecutionOrder(-100)]
    public class RemoteAmanClient : MonoBehaviour, IDataSource
    {
        [Header("Standalone backend")]
        [SerializeField] private string apiBaseUrl = "https://aman.baraaelbaba.com/api/v1/demo";
        [SerializeField] private int simulationRunId;
        [SerializeField] private float pollIntervalSeconds = 5f;
        [SerializeField] private float requestTimeoutSeconds = 15f;
        [SerializeField] private float positionRequestTimeoutSeconds = 30f;

        [Header("Connection diagnostics")]
        [SerializeField] private bool logBackendConnection = true;

        [Header("Entity prefabs")]
        [SerializeField] private GameObject attendeePrefab;
        [SerializeField] private GameObject responderPrefab;

        [Header("World mapping")]
        [SerializeField] private Transform coordinateOrigin;
        [SerializeField] private float metresToWorldUnits = 1f;
        [SerializeField] private float visualSpacingRadius = 3f;
        [SerializeField] private float minimumVisualSpacing = 0.85f;
        [SerializeField] private int visualPlacementAttempts = 14;
        [SerializeField] private float navMeshProjectionRadius = 3f;
        [SerializeField] private float navMeshFallbackProjectionRadius = 25f;
        [SerializeField] private float zoneEdgeInset = 0.6f;

        [Header("Remote movement")]
        [Tooltip("When enabled, each new backend route is traversed over Position Interpolation Seconds. When disabled, each person uses their individual walking speed.")]
        [SerializeField] private bool useTimedPositionInterpolation;
        [Tooltip("How long a person should take to traverse each newly received NavMesh route when timed interpolation is enabled.")]
        [Min(0.1f)]
        [SerializeField] private float positionInterpolationSeconds = 5f;
        [Tooltip("Adds deterministic intermediate NavMesh points so moving people use different visual lanes instead of sharing one path line.")]
        [SerializeField] private bool spreadMovementRoutes = true;
        [Tooltip("Maximum sideways distance used to spread walking routes. The backend destination is not changed.")]
        [Min(0f)]
        [SerializeField] private float movementRouteSpread = 1.5f;
        [Tooltip("Approximate distance between intermediate spread points along a route.")]
        [Min(0.5f)]
        [SerializeField] private float movementRouteWaypointSpacing = 4f;
        [Tooltip("Maximum distance Unity may project a spread point back onto the NavMesh.")]
        [Min(0.05f)]
        [SerializeField] private float movementRouteProjectionRadius = 0.6f;
        [SerializeField] private float minimumAttendeeSpeed = 1.1f;
        [SerializeField] private float maximumAttendeeSpeed = 1.9f;
        [SerializeField] private float minimumResponderSpeed = 1.8f;
        [SerializeField] private float maximumResponderSpeed = 2.8f;
        [SerializeField] private int pathCalculationsPerFrame = 250;
        [SerializeField] private float pathCornerArrivalDistance = 0.08f;

        public event Action<List<Cluster>> OnClustersUpdated;
        public event Action<Incident> OnIncidentChanged;
        public event Action<AmanSnapshot> OnSnapshotUpdated;
        public event Action<string, bool> OnConnectionChanged;

        public AmanSnapshot CurrentSnapshot { get; private set; }
        public bool IsConnected { get; private set; }
        public string ConnectionMessage { get; private set; } = "CONNECTING";

        private readonly Dictionary<string, RemoteEntity> entities = new Dictionary<string, RemoteEntity>();
        private readonly Dictionary<string, BackendResponder> responders = new Dictionary<string, BackendResponder>();
        private readonly Dictionary<string, Incident> incidents = new Dictionary<string, Incident>();
        private readonly Stack<GameObject> crowdPool = new Stack<GameObject>();
        private readonly Stack<GameObject> responderPool = new Stack<GameObject>();
        private readonly Queue<RemoteEntity> pendingPathEntities = new Queue<RemoteEntity>();
        private readonly Dictionary<string, PositionExtents> zonePositionExtents = new Dictionary<string, PositionExtents>();
        private int calibratedSimulationRunId;
        private int lastAppliedFocusSequence;
        private int lastReportedMissingFocusSequence;
        private int consecutiveConnectionFailures;

        private sealed class RemoteEntity
        {
            public string Id;
            public GameObject GameObject;
            public Vector3 Target;
            public Vector3 DataPosition;
            public Vector3[] PathCorners = Array.Empty<Vector3>();
            public int NextPathCorner;
            public float NaturalMovementSpeed;
            public float MovementSpeed;
            public float RestingYaw;
            public bool PathQueued;
            public bool WasMoving;
            public string ZoneId;
            public bool Reachable;
            public string Status;
            public string Type;
            public RemoteEntityVisual Visual;
        }

        private struct PositionExtents
        {
            public float MinX;
            public float MaxX;
            public float MinZ;
            public float MaxZ;
        }

        private void Awake()
        {
            ApplyCommandLineConfiguration();
            if (coordinateOrigin == null) coordinateOrigin = transform;
        }

        private void Start()
        {
            if (logBackendConnection)
            {
                Debug.Log($"[RemoteAmanClient] Connecting to hosted backend: {apiBaseUrl.TrimEnd('/')}"
                    + (simulationRunId > 0 ? $" (requested run {simulationRunId})" : " (discovering active run)"));
            }
            StartCoroutine(PollBackend());
        }

        private void Update()
        {
            ProcessPendingPaths();
            float deltaTime = Mathf.Max(0f, Time.unscaledDeltaTime);
            foreach (RemoteEntity entity in entities.Values)
            {
                if (entity.GameObject != null)
                {
                    Vector3 before = entity.GameObject.transform.position;
                    bool isMoving = MoveAlongPath(entity, deltaTime);
                    Vector3 direction = entity.GameObject.transform.position - before;
                    if (direction.sqrMagnitude < 0.000001f && isMoving && entity.NextPathCorner < entity.PathCorners.Length)
                    {
                        direction = entity.PathCorners[entity.NextPathCorner] - before;
                    }
                    entity.Visual?.SetMotion(direction, isMoving);
                    if (!isMoving && (entity.WasMoving || direction.sqrMagnitude > 0.000001f))
                    {
                        entity.Visual?.SetIdleFacing(entity.RestingYaw);
                    }
                    entity.WasMoving = isMoving;
                }
            }
        }

        private void ProcessPendingPaths()
        {
            int budget = Mathf.Max(1, pathCalculationsPerFrame);
            while (budget-- > 0 && pendingPathEntities.Count > 0)
            {
                RemoteEntity entity = pendingPathEntities.Dequeue();
                entity.PathQueued = false;
                if (entity.GameObject == null || !entity.GameObject.activeInHierarchy
                    || !entities.TryGetValue(entity.Id, out RemoteEntity active) || active != entity) continue;

                Vector3 start = entity.GameObject.transform.position;
                if ((start - entity.Target).sqrMagnitude <= pathCornerArrivalDistance * pathCornerArrivalDistance)
                {
                    entity.PathCorners = Array.Empty<Vector3>();
                    entity.NextPathCorner = 0;
                    continue;
                }

                NavMeshPath path = new NavMeshPath();
                if (NavMesh.CalculatePath(start, entity.Target, NavMesh.AllAreas, path) && path.corners.Length > 1)
                {
                    entity.PathCorners = BuildSpreadPath(entity.Id, path.corners);
                    entity.NextPathCorner = 1;
                    entity.MovementSpeed = useTimedPositionInterpolation
                        ? CalculatePathLength(entity.PathCorners) / Mathf.Max(0.1f, positionInterpolationSeconds)
                        : entity.NaturalMovementSpeed;
                    entity.Visual?.SetAnimationSpeed(entity.MovementSpeed);
                }
                else
                {
                    entity.PathCorners = Array.Empty<Vector3>();
                    entity.NextPathCorner = 0;
                }
            }
        }

        private bool MoveAlongPath(RemoteEntity entity, float deltaTime)
        {
            if (entity.PathCorners == null || entity.NextPathCorner >= entity.PathCorners.Length) return false;

            float remainingStep = Mathf.Max(0f, entity.MovementSpeed) * deltaTime;
            Transform entityTransform = entity.GameObject.transform;
            while (remainingStep > 0f && entity.NextPathCorner < entity.PathCorners.Length)
            {
                Vector3 corner = entity.PathCorners[entity.NextPathCorner];
                float distanceToCorner = Vector3.Distance(entityTransform.position, corner);
                if (distanceToCorner <= Mathf.Max(0.01f, pathCornerArrivalDistance))
                {
                    entityTransform.position = corner;
                    entity.NextPathCorner++;
                    continue;
                }

                float step = Mathf.Min(remainingStep, distanceToCorner);
                entityTransform.position = Vector3.MoveTowards(entityTransform.position, corner, step);
                remainingStep -= step;
                if (step >= distanceToCorner - 0.0001f) entity.NextPathCorner++;
            }

            return entity.NextPathCorner < entity.PathCorners.Length;
        }

        private IEnumerator PollBackend()
        {
            while (enabled)
            {
                float cycleStartedAt = Time.realtimeSinceStartup;
                if (!IsConnected && consecutiveConnectionFailures > 0 && logBackendConnection)
                {
                    Debug.Log($"[RemoteAmanClient] Reconnect attempt {consecutiveConnectionFailures + 1} starting now.");
                }
                if (simulationRunId <= 0) yield return DiscoverRun();
                if (simulationRunId > 0) yield return FetchSnapshot();
                float cycleElapsed = Time.realtimeSinceStartup - cycleStartedAt;
                float wait = Mathf.Max(0.1f, Mathf.Max(1f, pollIntervalSeconds) - cycleElapsed);
                yield return new WaitForSecondsRealtime(wait);
            }
        }

        private IEnumerator DiscoverRun()
        {
            using UnityWebRequest request = UnityWebRequest.Get($"{apiBaseUrl.TrimEnd('/')}/simulations");
            request.timeout = Mathf.CeilToInt(requestTimeoutSeconds);
            yield return request.SendWebRequest();
            if (request.result != UnityWebRequest.Result.Success)
            {
                HandleConnectionFailure(request);
                yield break;
            }

            SimulationListResponse list = JsonUtility.FromJson<SimulationListResponse>(request.downloadHandler.text);
            SimulationListItem run = list?.data?.data?.FirstOrDefault(item => item.status != "stopped")
                ?? list?.data?.data?.FirstOrDefault();
            if (run == null)
            {
                HandleConnectionFailure("NO SIMULATION RUN", request.url);
                yield break;
            }
            simulationRunId = run.id;
            if (logBackendConnection) Debug.Log($"[RemoteAmanClient] Discovered simulation run {simulationRunId} ({run.status}).");
        }

        private IEnumerator FetchSnapshot()
        {
            string baseRunUrl = $"{apiBaseUrl.TrimEnd('/')}/simulations/{simulationRunId}";
            using UnityWebRequest controlRequest = UnityWebRequest.Get(baseRunUrl);
            controlRequest.timeout = Mathf.CeilToInt(requestTimeoutSeconds);
            yield return controlRequest.SendWebRequest();
            if (controlRequest.result != UnityWebRequest.Result.Success)
            {
                if (controlRequest.responseCode == 404) simulationRunId = 0;
                HandleConnectionFailure(controlRequest);
                yield break;
            }

            AmanSnapshot snapshot = JsonUtility.FromJson<AmanSnapshot>(controlRequest.downloadHandler.text);
            if (snapshot == null || snapshot.id <= 0)
            {
                HandleConnectionFailure("INVALID BACKEND RESPONSE", baseRunUrl, ResponsePreview(controlRequest));
                yield break;
            }

            PrepareSimulationRun(snapshot.id);
            ApplyOperatorFocus(snapshot);

            using UnityWebRequest positionRequest = UnityWebRequest.Get(
                $"{baseRunUrl}?client=unity&offset=0&limit=10000");
            positionRequest.SetRequestHeader("Accept-Encoding", "gzip");
            positionRequest.SetRequestHeader("Accept", "application/json");
            positionRequest.timeout = Mathf.CeilToInt(Mathf.Max(requestTimeoutSeconds, positionRequestTimeoutSeconds));
            yield return positionRequest.SendWebRequest();
            if (positionRequest.result != UnityWebRequest.Result.Success)
            {
                HandleConnectionFailure(positionRequest);
                yield break;
            }
            AmanSnapshot positionSnapshot = JsonUtility.FromJson<AmanSnapshot>(positionRequest.downloadHandler.text);
            snapshot.positions = positionSnapshot?.positions ?? Array.Empty<BackendPosition>();
            snapshot.responderPositions = positionSnapshot?.responderPositions ?? snapshot.responderPositions;
            snapshot.coordinateSystem = positionSnapshot?.coordinateSystem;

            ApplySnapshot(snapshot);
            SetConnection(true, snapshot.stale ? "DATA OUTDATED" : snapshot.status.ToUpperInvariant());
        }

        private void ApplySnapshot(AmanSnapshot snapshot)
        {
            CurrentSnapshot = snapshot;
            PrepareSimulationRun(snapshot.id);
            responders.Clear();
            foreach (BackendResponder responder in snapshot.responders ?? Array.Empty<BackendResponder>()) responders[responder.id] = responder;

            HashSet<string> seen = new HashSet<string>();
            Dictionary<Vector2Int, List<Vector3>> occupiedVisualCells = new Dictionary<Vector2Int, List<Vector3>>();
            BackendPosition[] positions = snapshot.positions ?? Array.Empty<BackendPosition>();
            BackendPosition[] usablePositions = positions
                .Where(position => position.quality != "missing")
                .ToArray();
            UpdatePositionExtents(usablePositions.Where(position => !string.IsNullOrEmpty(position.zoneId)));

            foreach (BackendPosition position in positions.Where(position => position.quality == "missing"))
            {
                if (entities.TryGetValue(position.id, out RemoteEntity existing) && existing.Type == "crowd")
                {
                    seen.Add(position.id);
                    existing.Status = position.quality;
                }
            }

            IEnumerable<BackendPosition> selectedPositions = usablePositions
                .OrderBy(position => StableHash(position.id));
            foreach (BackendPosition position in selectedPositions)
            {
                Vector3 mapped = MapToScene(snapshot, position.x, position.z, position.zoneId, zonePositionExtents);
                if (!TryPrepareVisualPosition(position.id, mapped, occupiedVisualCells, out Vector3 visualPosition)) continue;
                UpdateEntity(position.id, "crowd", visualPosition, mapped, position.zoneId, true, position.quality, seen);
            }
            foreach (BackendResponderPosition position in snapshot.responderPositions ?? Array.Empty<BackendResponderPosition>())
            {
                BackendResponder responder = responders.TryGetValue(position.id, out BackendResponder value) ? value : null;
                bool reachable = responder?.signals?.reachability?.dataReachable ?? false;
                string zoneId = FindBackendZoneAt(snapshot, position.x, position.z);
                Vector3 mapped = MapToScene(snapshot, position.x, position.z, zoneId);
                if (!TryProjectToNavMesh(mapped, out NavMeshHit responderHit)) continue;
                mapped = responderHit.position;
                UpdateEntity(position.id, "responder", mapped, mapped, zoneId, reachable,
                    position.missionStatus ?? (position.available ? "Available" : "Unavailable"), seen);
            }
            RemoveMissingEntities(seen);
            PublishZones(snapshot);
            PublishIncidents(snapshot);
            ApplyOperatorFocus(snapshot);
            OnSnapshotUpdated?.Invoke(snapshot);
        }

        private void PrepareSimulationRun(int runId)
        {
            if (calibratedSimulationRunId != runId)
            {
                zonePositionExtents.Clear();
                calibratedSimulationRunId = runId;
                lastAppliedFocusSequence = 0;
                lastReportedMissingFocusSequence = 0;
            }
        }

        private void ApplyOperatorFocus(AmanSnapshot snapshot)
        {
            BackendFocus focus = snapshot.focus;
            if (focus == null || focus.sequence <= 0 || focus.sequence <= lastAppliedFocusSequence) return;

            if (ZoneManager.Instance == null)
            {
                ReportMissingFocusOnce(focus, "ZoneManager is not available");
                return;
            }

            if (string.IsNullOrEmpty(focus.zoneId))
            {
                ZoneManager.Instance.RequestHomeJump();
                lastAppliedFocusSequence = focus.sequence;
                return;
            }

            BackendZone backendZone = snapshot.zones?.FirstOrDefault(value => value.id == focus.zoneId)
                ?? new BackendZone { id = focus.zoneId, name = focus.zoneName };
            Zone sceneZone = FindSceneZone(backendZone);
            if (sceneZone == null)
            {
                ReportMissingFocusOnce(focus, $"no authored Unity zone matches '{focus.zoneName ?? focus.zoneKey ?? focus.zoneId}'");
                return;
            }

            ZoneManager.Instance.RequestZoneJump(sceneZone);
            lastAppliedFocusSequence = focus.sequence;
            Debug.Log($"[RemoteAmanClient] Applied operator focus #{focus.sequence}: {sceneZone.displayName}"
                + (string.IsNullOrEmpty(focus.incidentId) ? string.Empty : $" (incident {focus.incidentId})"));
        }

        private void ReportMissingFocusOnce(BackendFocus focus, string reason)
        {
            if (lastReportedMissingFocusSequence == focus.sequence) return;
            lastReportedMissingFocusSequence = focus.sequence;
            Debug.LogWarning($"[RemoteAmanClient] Cannot apply operator focus #{focus.sequence}: {reason}.");
        }

        private void UpdateEntity(string id, string type, Vector3 target, Vector3 dataPosition, string zoneId, bool reachable, string status, HashSet<string> seen)
        {
            if (string.IsNullOrEmpty(id)) return;
            seen.Add(id);
            if (!entities.TryGetValue(id, out RemoteEntity entity))
            {
                GameObject instance = AcquireEntity(type, target);
                if (instance == null) return;
                instance.name = type == "responder" ? $"Responder_{id}" : $"Attendee_{id}";
                UnityEngine.AI.NavMeshAgent agent = instance.GetComponent<UnityEngine.AI.NavMeshAgent>();
                if (agent != null) agent.enabled = false;
                SetLayerRecursively(instance, LayerMask.NameToLayer("Selectable"));
                RemoteEntityVisual visual = instance.GetComponent<RemoteEntityVisual>() ?? instance.AddComponent<RemoteEntityVisual>();
                visual.Configure(StableHash(id), type == "responder");
                EnsureSelectionCollider(instance);
                SelectableEntity selectable = instance.GetComponent<SelectableEntity>() ?? instance.AddComponent<SelectableEntity>();
                selectable.Initialize(id, type);
                float naturalMovementSpeed = MovementSpeedFor(id, type);
                entity = new RemoteEntity
                {
                    Id = id,
                    GameObject = instance,
                    Type = type,
                    Visual = visual,
                    Target = target,
                    NaturalMovementSpeed = naturalMovementSpeed,
                    MovementSpeed = naturalMovementSpeed,
                    RestingYaw = RestingYawFor(id, target)
                };
                visual.SetAnimationSpeed(entity.MovementSpeed);
                entities[id] = entity;
            }
            else if ((entity.Target - target).sqrMagnitude > 0.0001f)
            {
                entity.Target = target;
                entity.RestingYaw = RestingYawFor(id, target);
                QueuePath(entity);
            }
            entity.DataPosition = dataPosition;
            entity.ZoneId = zoneId;
            entity.Reachable = reachable;
            entity.Status = status;
        }

        private void QueuePath(RemoteEntity entity)
        {
            if (entity.PathQueued) return;
            entity.PathQueued = true;
            pendingPathEntities.Enqueue(entity);
        }

        private float MovementSpeedFor(string id, string type)
        {
            uint hash = StableHash(id);
            float amount = ((hash >> 8) & 0xffff) / 65535f;
            if (type == "responder")
            {
                return Mathf.Lerp(Mathf.Min(minimumResponderSpeed, maximumResponderSpeed),
                    Mathf.Max(minimumResponderSpeed, maximumResponderSpeed), amount);
            }
            return Mathf.Lerp(Mathf.Min(minimumAttendeeSpeed, maximumAttendeeSpeed),
                Mathf.Max(minimumAttendeeSpeed, maximumAttendeeSpeed), amount);
        }

        private static float CalculatePathLength(Vector3[] corners)
        {
            float length = 0f;
            for (int i = 1; i < corners.Length; i++)
            {
                length += Vector3.Distance(corners[i - 1], corners[i]);
            }
            return length;
        }

        private Vector3[] BuildSpreadPath(string entityId, Vector3[] pathCorners)
        {
            if (!spreadMovementRoutes || movementRouteSpread <= 0f || pathCorners.Length < 2)
            {
                return pathCorners;
            }

            uint hash = StableHash(entityId);
            float signedLane = ((hash & 0xffff) / 65535f) * 2f - 1f;
            if (Mathf.Abs(signedLane) < 0.2f)
            {
                signedLane = signedLane < 0f ? -0.2f : 0.2f;
            }

            float spacing = Mathf.Max(0.5f, movementRouteWaypointSpacing);
            List<Vector3> spreadCorners = new List<Vector3>(pathCorners.Length * 2) { pathCorners[0] };
            for (int segmentIndex = 1; segmentIndex < pathCorners.Length; segmentIndex++)
            {
                Vector3 segmentStart = pathCorners[segmentIndex - 1];
                Vector3 segmentEnd = pathCorners[segmentIndex];
                Vector3 segment = segmentEnd - segmentStart;
                segment.y = 0f;
                float segmentLength = segment.magnitude;
                int divisions = Mathf.Max(1, Mathf.CeilToInt(segmentLength / spacing));
                if (segmentLength > 0.01f && divisions > 1)
                {
                    Vector3 sideways = Vector3.Cross(Vector3.up, segment / segmentLength);
                    for (int division = 1; division < divisions; division++)
                    {
                        float progress = division / (float)divisions;
                        Vector3 centre = Vector3.Lerp(segmentStart, segmentEnd, progress);
                        float cornerTaper = Mathf.Sin(progress * Mathf.PI);
                        float variedLane = signedLane * (0.75f + Hash01(hash, segmentIndex, division) * 0.25f);
                        float offset = variedLane * movementRouteSpread * cornerTaper;
                        Vector3 preferred = centre + sideways * offset;
                        if (TryProjectSpreadPoint(preferred, out Vector3 projected)
                            || TryProjectSpreadPoint(centre - sideways * offset, out projected))
                        {
                            spreadCorners.Add(projected);
                        }
                    }
                }
                spreadCorners.Add(segmentEnd);
            }
            return spreadCorners.ToArray();
        }

        private bool TryProjectSpreadPoint(Vector3 candidate, out Vector3 projected)
        {
            if (NavMesh.SamplePosition(candidate, out NavMeshHit hit,
                Mathf.Max(0.05f, movementRouteProjectionRadius), NavMesh.AllAreas))
            {
                projected = hit.position;
                return true;
            }
            projected = default;
            return false;
        }

        private static float Hash01(uint baseHash, int segmentIndex, int division)
        {
            unchecked
            {
                uint hash = baseHash;
                hash = (hash ^ (uint)segmentIndex) * 16777619u;
                hash = (hash ^ (uint)division) * 16777619u;
                return (hash & 0xffff) / 65535f;
            }
        }

        private static float RestingYawFor(string id, Vector3 target)
        {
            string arrival = $"{id}:{Mathf.RoundToInt(target.x * 10f)}:{Mathf.RoundToInt(target.z * 10f)}";
            return StableHash(arrival) % 360u;
        }

        private GameObject AcquireEntity(string type, Vector3 position)
        {
            Stack<GameObject> pool = type == "responder" ? responderPool : crowdPool;
            while (pool.Count > 0)
            {
                GameObject pooled = pool.Pop();
                if (pooled == null) continue;
                pooled.transform.position = position;
                pooled.SetActive(true);
                return pooled;
            }

            GameObject prefab = type == "responder" && responderPrefab != null ? responderPrefab : attendeePrefab;
            return prefab != null ? Instantiate(prefab, position, Quaternion.identity, transform) : null;
        }

        private bool TryPrepareVisualPosition(string id, Vector3 mapped, Dictionary<Vector2Int, List<Vector3>> occupied, out Vector3 result)
        {
            uint hash = StableHash(id);
            float baseAngle = (hash & 0xffff) / 65535f * Mathf.PI * 2f;
            float radialOffset = ((hash >> 16) & 0xffff) / 65535f;
            int attempts = Mathf.Max(1, visualPlacementAttempts);
            bool foundNavMesh = false;
            Vector3 closestNavMeshPosition = default;
            for (int attempt = 0; attempt < attempts; attempt++)
            {
                float fraction = attempt == 0 ? 0f : (attempt - 1f + radialOffset) / Mathf.Max(1, attempts - 1);
                float radius = attempt == 0 ? 0f : Mathf.Sqrt(fraction) * visualSpacingRadius;
                float angle = baseAngle + attempt * 2.39996323f;
                Vector3 candidate = mapped + new Vector3(Mathf.Cos(angle), 0f, Mathf.Sin(angle)) * radius;
                if (!NavMesh.SamplePosition(candidate, out NavMeshHit hit, navMeshProjectionRadius, NavMesh.AllAreas)) continue;
                candidate = hit.position;
                if (!foundNavMesh)
                {
                    foundNavMesh = true;
                    closestNavMeshPosition = candidate;
                }
                if (!HasVisualClearance(candidate, occupied)) continue;
                AddOccupiedPosition(candidate, occupied);
                result = candidate;
                return true;
            }

            if (foundNavMesh)
            {
                AddOccupiedPosition(closestNavMeshPosition, occupied);
                result = closestNavMeshPosition;
                return true;
            }

            if (NavMesh.SamplePosition(mapped, out NavMeshHit fallbackHit,
                    Mathf.Max(navMeshProjectionRadius, navMeshFallbackProjectionRadius), NavMesh.AllAreas))
            {
                AddOccupiedPosition(fallbackHit.position, occupied);
                result = fallbackHit.position;
                return true;
            }

            result = default;
            return false;
        }

        private bool TryProjectToNavMesh(Vector3 mapped, out NavMeshHit hit)
        {
            if (NavMesh.SamplePosition(mapped, out hit, navMeshProjectionRadius, NavMesh.AllAreas)) return true;
            return NavMesh.SamplePosition(mapped, out hit,
                Mathf.Max(navMeshProjectionRadius, navMeshFallbackProjectionRadius), NavMesh.AllAreas);
        }

        private bool HasVisualClearance(Vector3 candidate, Dictionary<Vector2Int, List<Vector3>> occupied)
        {
            float spacing = Mathf.Max(0.1f, minimumVisualSpacing);
            Vector2Int cell = VisualCell(candidate, spacing);
            float spacingSquared = spacing * spacing;
            for (int x = -1; x <= 1; x++)
            for (int z = -1; z <= 1; z++)
            {
                if (!occupied.TryGetValue(cell + new Vector2Int(x, z), out List<Vector3> nearby)) continue;
                foreach (Vector3 other in nearby)
                {
                    Vector2 delta = new Vector2(candidate.x - other.x, candidate.z - other.z);
                    if (delta.sqrMagnitude < spacingSquared) return false;
                }
            }
            return true;
        }

        private void AddOccupiedPosition(Vector3 position, Dictionary<Vector2Int, List<Vector3>> occupied)
        {
            float spacing = Mathf.Max(0.1f, minimumVisualSpacing);
            Vector2Int cell = VisualCell(position, spacing);
            if (!occupied.TryGetValue(cell, out List<Vector3> positions))
            {
                positions = new List<Vector3>();
                occupied[cell] = positions;
            }
            positions.Add(position);
        }

        private static Vector2Int VisualCell(Vector3 position, float cellSize) =>
            new Vector2Int(Mathf.FloorToInt(position.x / cellSize), Mathf.FloorToInt(position.z / cellSize));

        private static void EnsureSelectionCollider(GameObject instance)
        {
            if (instance.GetComponentInChildren<Collider>(true) != null) return;
            Renderer[] renderers = instance.GetComponentsInChildren<Renderer>(true);
            if (renderers.Length == 0) return;

            Bounds visualBounds = renderers[0].bounds;
            for (int i = 1; i < renderers.Length; i++) visualBounds.Encapsulate(renderers[i].bounds);
            Vector3 localCenter = instance.transform.InverseTransformPoint(visualBounds.center);
            Vector3 scale = instance.transform.lossyScale;
            float height = visualBounds.size.y / Mathf.Max(0.001f, Mathf.Abs(scale.y));
            float width = Mathf.Max(visualBounds.size.x / Mathf.Max(0.001f, Mathf.Abs(scale.x)),
                visualBounds.size.z / Mathf.Max(0.001f, Mathf.Abs(scale.z)));
            CapsuleCollider selectionCollider = instance.AddComponent<CapsuleCollider>();
            selectionCollider.center = localCenter;
            selectionCollider.height = Mathf.Max(height, 1f);
            selectionCollider.radius = Mathf.Clamp(width * 0.5f, 0.25f, selectionCollider.height * 0.45f);
        }

        private Vector3 ToWorld(float x, float z)
        {
            Vector3 origin = coordinateOrigin != null ? coordinateOrigin.position : Vector3.zero;
            return origin + new Vector3(x * metresToWorldUnits, 0f, z * metresToWorldUnits);
        }

        private void UpdatePositionExtents(IEnumerable<BackendPosition> positions)
        {
            foreach (BackendPosition position in positions)
            {
                if (string.IsNullOrEmpty(position.zoneId)) continue;
                if (!zonePositionExtents.TryGetValue(position.zoneId, out PositionExtents extents))
                {
                    zonePositionExtents[position.zoneId] = new PositionExtents
                    {
                        MinX = position.x,
                        MaxX = position.x,
                        MinZ = position.z,
                        MaxZ = position.z
                    };
                    continue;
                }

                extents.MinX = Mathf.Min(extents.MinX, position.x);
                extents.MaxX = Mathf.Max(extents.MaxX, position.x);
                extents.MinZ = Mathf.Min(extents.MinZ, position.z);
                extents.MaxZ = Mathf.Max(extents.MaxZ, position.z);
                zonePositionExtents[position.zoneId] = extents;
            }
        }

        private Vector3 MapToScene(AmanSnapshot snapshot, float x, float z, string backendZoneId,
            IReadOnlyDictionary<string, PositionExtents> positionExtents = null)
        {
            BackendZone backendZone = snapshot.zones?.FirstOrDefault(value => value.id == backendZoneId);
            Zone sceneZone = FindSceneZone(backendZone);
            if (backendZone?.boundary == null || backendZone.boundary.Length < 3 || snapshot.coordinateSystem?.origin == null || sceneZone == null)
                return ToWorld(x, z);

            BackendGeoPoint origin = snapshot.coordinateSystem.origin;
            float cosine = Mathf.Cos(origin.latitude * Mathf.Deg2Rad);
            float minX = float.MaxValue, maxX = float.MinValue, minZ = float.MaxValue, maxZ = float.MinValue;
            foreach (BackendGeoPoint point in backendZone.boundary)
            {
                float boundaryX = (point.longitude - origin.longitude) * cosine * 111320f;
                float boundaryZ = (point.latitude - origin.latitude) * 111320f;
                minX = Mathf.Min(minX, boundaryX); maxX = Mathf.Max(maxX, boundaryX);
                minZ = Mathf.Min(minZ, boundaryZ); maxZ = Mathf.Max(maxZ, boundaryZ);
            }

            if (positionExtents != null && !string.IsNullOrEmpty(backendZoneId)
                && positionExtents.TryGetValue(backendZoneId, out PositionExtents extents))
            {
                if (extents.MaxX - extents.MinX > 0.01f)
                {
                    minX = extents.MinX;
                    maxX = extents.MaxX;
                }
                if (extents.MaxZ - extents.MinZ > 0.01f)
                {
                    minZ = extents.MinZ;
                    maxZ = extents.MaxZ;
                }
            }

            Bounds bounds = sceneZone.WorldBounds;
            float marginX = Mathf.Min(Mathf.Max(0f, zoneEdgeInset), bounds.extents.x * 0.25f);
            float marginZ = Mathf.Min(Mathf.Max(0f, zoneEdgeInset), bounds.extents.z * 0.25f);
            float normalizedX = Mathf.InverseLerp(minX, maxX, x);
            float normalizedZ = Mathf.InverseLerp(minZ, maxZ, z);
            float worldY = coordinateOrigin != null ? coordinateOrigin.position.y : bounds.min.y;
            return new Vector3(
                Mathf.Lerp(bounds.min.x + marginX, bounds.max.x - marginX, normalizedX),
                worldY,
                Mathf.Lerp(bounds.min.z + marginZ, bounds.max.z - marginZ, normalizedZ));
        }

        private string FindBackendZoneAt(AmanSnapshot snapshot, float x, float z)
        {
            if (snapshot.zones == null || snapshot.coordinateSystem?.origin == null) return null;
            BackendGeoPoint origin = snapshot.coordinateSystem.origin;
            float cosine = Mathf.Cos(origin.latitude * Mathf.Deg2Rad);
            foreach (BackendZone zone in snapshot.zones)
            {
                if (zone.boundary == null || zone.boundary.Length < 3) continue;
                float minX = float.MaxValue, maxX = float.MinValue, minZ = float.MaxValue, maxZ = float.MinValue;
                foreach (BackendGeoPoint point in zone.boundary)
                {
                    float boundaryX = (point.longitude - origin.longitude) * cosine * 111320f;
                    float boundaryZ = (point.latitude - origin.latitude) * 111320f;
                    minX = Mathf.Min(minX, boundaryX); maxX = Mathf.Max(maxX, boundaryX);
                    minZ = Mathf.Min(minZ, boundaryZ); maxZ = Mathf.Max(maxZ, boundaryZ);
                }
                if (x >= minX && x <= maxX && z >= minZ && z <= maxZ) return zone.id;
            }
            return null;
        }

        private void RemoveMissingEntities(HashSet<string> seen)
        {
            foreach (string id in entities.Keys.Where(id => !seen.Contains(id)).ToArray())
            {
                RemoteEntity entity = entities[id];
                if (entity.GameObject != null)
                {
                    entity.GameObject.SetActive(false);
                    (entity.Type == "responder" ? responderPool : crowdPool).Push(entity.GameObject);
                }
                entities.Remove(id);
            }
        }

        private void PublishZones(AmanSnapshot snapshot)
        {
            List<Cluster> clusters = new List<Cluster>();
            foreach (BackendZone zone in snapshot.zones ?? Array.Empty<BackendZone>())
            {
                Zone sceneZone = FindSceneZone(zone);
                float density = zone.latest_reading?.densityPerSquareMeter ?? 0f;
                int classification = zone.risk_level == "critical" ? 3 : zone.risk_level == "warning" ? 2 : 1;
                clusters.Add(new Cluster { ZoneId = sceneZone != null ? sceneZone.id : zone.id, CellId = zone.id, DeviceCount = zone.latest_reading?.deviceCount ?? 0,
                    DensityPerSqm = density, Classification = classification, ComputedAt = Time.realtimeSinceStartup });
            }
            OnClustersUpdated?.Invoke(clusters);
        }

        private void PublishIncidents(AmanSnapshot snapshot)
        {
            HashSet<string> active = new HashSet<string>();
            foreach (BackendIncident backend in snapshot.incidents ?? Array.Empty<BackendIncident>())
            {
                active.Add(backend.id);
                string responderId = backend.assigned_responder_id ?? backend.responder_id;
                responders.TryGetValue(responderId ?? string.Empty, out BackendResponder responder);
                Incident incident = new Incident { IncidentId = backend.id, ZoneId = backend.zone_id,
                    Status = backend.status, ResponderId = responderId, ResponderName = responder?.name,
                    IsResolved = backend.status == "resolved" || string.IsNullOrEmpty(backend.active_zone_id) };
                incidents[backend.id] = incident;
                OnIncidentChanged?.Invoke(incident);
            }
            foreach (string id in incidents.Keys.Where(id => !active.Contains(id)).ToArray())
            {
                Incident resolved = incidents[id];
                resolved.IsResolved = true;
                OnIncidentChanged?.Invoke(resolved);
                incidents.Remove(id);
            }
        }

        public List<DevicePresence> GetCurrentPresences()
        {
            return entities.Select(pair => new DevicePresence { DeviceId = pair.Key, ZoneId = pair.Value.ZoneId,
                Position = pair.Value.DataPosition,
                LastSeenTimestamp = Time.realtimeSinceStartup, IsReachable = pair.Value.Reachable }).ToList();
        }

        public SelectionDetail GetSelectionDetail(string entityId, string entityType)
        {
            if (!entities.TryGetValue(entityId, out RemoteEntity entity)) return null;
            responders.TryGetValue(entityId, out BackendResponder responder);
            return new SelectionDetail { Id = entityId, EntityType = entityType,
                DisplayName = entityType == "responder" ? responder?.name : null, ZoneId = DisplayZone(entity.ZoneId),
                IsReachable = entity.Reachable, Status = entity.Status };
        }

        public void ApproveIncident(string incidentId, string responderId, Action<bool, string> completed)
        {
            StartCoroutine(Post($"incidents/{incidentId}/approve", JsonUtility.ToJson(new RouteReviewRequest { responderId = responderId }), completed));
        }

        public void ResolveIncident(string incidentId, Action<bool, string> completed)
        {
            StartCoroutine(Post($"incidents/{incidentId}/resolve", JsonUtility.ToJson(new ResolveRequest()), completed));
        }

        private IEnumerator Post(string path, string body, Action<bool, string> completed)
        {
            using UnityWebRequest request = new UnityWebRequest($"{apiBaseUrl.TrimEnd('/')}/{path}", "POST");
            byte[] bytes = System.Text.Encoding.UTF8.GetBytes(body);
            request.uploadHandler = new UploadHandlerRaw(bytes);
            request.downloadHandler = new DownloadHandlerBuffer();
            request.SetRequestHeader("Content-Type", "application/json");
            request.SetRequestHeader("Accept", "application/json");
            request.timeout = Mathf.CeilToInt(requestTimeoutSeconds);
            yield return request.SendWebRequest();
            bool success = request.result == UnityWebRequest.Result.Success;
            completed?.Invoke(success, success ? "Confirmed by backend" : ErrorMessage(request));
            if (success) yield return FetchSnapshot();
        }

        private string DisplayZone(string id)
        {
            return CurrentSnapshot?.zones?.FirstOrDefault(zone => zone.id == id)?.name ?? id ?? "Unknown";
        }

        private void HandleConnectionFailure(UnityWebRequest request)
        {
            HandleConnectionFailure(ErrorMessage(request), request.url, ResponsePreview(request));
        }

        private void HandleConnectionFailure(string message, string requestUrl, string responsePreview = null)
        {
            consecutiveConnectionFailures++;
            if (logBackendConnection)
            {
                string state = IsConnected ? "Disconnected" : "Connection failed";
                Debug.LogWarning($"[RemoteAmanClient] {state}: {message}. Request: {requestUrl}."
                    + $" Will retry automatically in {Mathf.Max(1f, pollIntervalSeconds):0.#} seconds"
                    + $" (failure {consecutiveConnectionFailures})."
                    + (string.IsNullOrEmpty(responsePreview) ? string.Empty : $" Response preview: {responsePreview}"));
            }
            SetConnection(false, message);
        }

        private static string ResponsePreview(UnityWebRequest request)
        {
            string body = request.downloadHandler?.text;
            if (string.IsNullOrWhiteSpace(body)) return null;
            string singleLine = body.Replace('\r', ' ').Replace('\n', ' ').Trim();
            return singleLine.Length <= 300 ? singleLine : singleLine.Substring(0, 300) + "...";
        }

        private static string ErrorMessage(UnityWebRequest request)
        {
            string detail = string.IsNullOrWhiteSpace(request.error)
                ? request.result.ToString()
                : request.error;
            if (request.responseCode >= 200 && request.responseCode < 300)
            {
                return $"BACKEND RESPONSE ERROR (HTTP {request.responseCode}): {detail}".ToUpperInvariant();
            }
            if (request.responseCode > 0) return $"BACKEND HTTP {request.responseCode}: {detail}".ToUpperInvariant();
            return string.IsNullOrWhiteSpace(detail) ? "BACKEND UNAVAILABLE" : detail.ToUpperInvariant();
        }

        private void SetConnection(bool connected, string message)
        {
            bool wasConnected = IsConnected;
            IsConnected = connected;
            ConnectionMessage = message;
            if (connected)
            {
                if (logBackendConnection && (!wasConnected || consecutiveConnectionFailures > 0))
                {
                    Debug.Log($"[RemoteAmanClient] Connected to hosted backend. Run {simulationRunId},"
                        + $" revision {CurrentSnapshot?.revision ?? 0}. Polling every {Mathf.Max(1f, pollIntervalSeconds):0.#} seconds.");
                }
                consecutiveConnectionFailures = 0;
            }
            OnConnectionChanged?.Invoke(message, connected);
        }

        private void ApplyCommandLineConfiguration()
        {
            string[] args = Environment.GetCommandLineArgs();
            for (int i = 0; i < args.Length - 1; i++)
            {
                if (args[i] == "--aman-api") apiBaseUrl = args[++i];
                else if (args[i] == "--aman-run" && int.TryParse(args[++i], NumberStyles.Integer, CultureInfo.InvariantCulture, out int id)) simulationRunId = id;
            }
        }

        private static void SetLayerRecursively(GameObject target, int layer)
        {
            if (layer < 0) return;
            target.layer = layer;
            foreach (Transform child in target.transform) SetLayerRecursively(child.gameObject, layer);
        }

        public Zone FindSceneZone(string backendZoneId)
        {
            BackendZone backendZone = CurrentSnapshot?.zones?.FirstOrDefault(value => value.id == backendZoneId);
            return FindSceneZone(backendZone);
        }

        public bool TryGetCrowdBounds(string backendZoneId, out Bounds bounds)
        {
            RemoteEntity[] crowd = entities.Values
                .Where(value => value.Type == "crowd" && value.ZoneId == backendZoneId && value.GameObject != null)
                .ToArray();
            if (crowd.Length == 0)
            {
                bounds = default;
                return false;
            }

            bounds = new Bounds(crowd[0].GameObject.transform.position, Vector3.zero);
            foreach (RemoteEntity entity in crowd) bounds.Encapsulate(entity.GameObject.transform.position);
            bounds.Expand(new Vector3(8f, 6f, 8f));
            return true;
        }

        private Zone FindSceneZone(BackendZone backendZone)
        {
            if (backendZone == null || ZoneManager.Instance == null) return null;
            string sceneName = backendZone.name switch
            {
                "East Entrance" => "East Stand",
                "West Exit" => "West Stand",
                "North Concourse" => "North Concourse",
                "South Concourse" => "South Concourse",
                _ => backendZone.name
            };
            IReadOnlyList<Zone> sceneZones = ZoneManager.Instance.GetZones();
            return sceneZones.FirstOrDefault(value =>
                string.Equals(value.displayName, sceneName, StringComparison.OrdinalIgnoreCase) ||
                string.Equals(value.displayName, backendZone.name, StringComparison.OrdinalIgnoreCase) ||
                string.Equals(value.id, backendZone.id, StringComparison.OrdinalIgnoreCase));
        }

        private static uint StableHash(string value)
        {
            unchecked
            {
                uint hash = 2166136261;
                foreach (char character in value ?? string.Empty) hash = (hash ^ character) * 16777619;
                hash ^= hash >> 16;
                hash *= 0x7feb352d;
                hash ^= hash >> 15;
                return hash;
            }
        }
    }
}
