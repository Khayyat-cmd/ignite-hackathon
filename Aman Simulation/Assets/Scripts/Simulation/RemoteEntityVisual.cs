using UnityEngine;
using UnityEngine.Rendering;

namespace Aman.Simulation
{
    public class RemoteEntityVisual : MonoBehaviour
    {
        private Transform visualRoot;
        private Renderer[] renderers;
        private Vector3 visualBasePosition;
        private float phase;
        private float bobAmount;
        private bool moving;
        private bool canBob;
        private Animator animator;
        private bool animatorWalking;
        private float walkingAnimationSpeed = 1f;
        private bool hasIdleFacing;
        private Quaternion idleFacing;

        private static readonly int IdleState = Animator.StringToHash("Base Layer.Idle");
        private static readonly int WalkingState = Animator.StringToHash("Base Layer.Walking");

        public void Configure(uint seed, bool responder)
        {
            renderers = GetComponentsInChildren<Renderer>(true);
            visualRoot = null;
            foreach (Renderer item in renderers)
            {
                if (item.transform != transform) { visualRoot = item.transform; break; }
            }
            visualRoot ??= transform;
            animator = GetComponentInChildren<Animator>(true);
            bool hasMovementStates = animator != null && animator.runtimeAnimatorController != null
                && animator.HasState(0, IdleState) && animator.HasState(0, WalkingState);
            if (!hasMovementStates) animator = null;
            else
            {
                animator.applyRootMotion = false;
                animatorWalking = false;
                animator.Play(IdleState, 0, 0f);
            }
            canBob = animator == null && visualRoot != transform;
            visualBasePosition = visualRoot.localPosition;
            phase = (seed % 1000) / 1000f * Mathf.PI * 2f;
            bobAmount = responder ? 0.018f : 0.012f + ((seed >> 8) % 100) / 100f * 0.012f;

            float scale = responder ? 1.04f : Mathf.Lerp(0.94f, 1.06f, ((seed >> 16) % 1000) / 999f);
            transform.localScale = Vector3.one * scale;
            transform.rotation = Quaternion.Euler(0f, seed % 360, 0f);

            foreach (Renderer item in renderers)
            {
                item.enabled = true;
                item.shadowCastingMode = responder ? ShadowCastingMode.On : ShadowCastingMode.Off;
                item.receiveShadows = responder;
                if (item is SkinnedMeshRenderer skinned) skinned.updateWhenOffscreen = false;
                ApplyTint(item, seed, responder);
            }
        }

        private static void ApplyTint(Renderer target, uint seed, bool responder)
        {
            Color[] attendeePalette =
            {
                new Color(0.60f, 0.69f, 0.80f),
                new Color(0.76f, 0.69f, 0.59f),
                new Color(0.59f, 0.67f, 0.64f),
                new Color(0.70f, 0.62f, 0.70f),
                new Color(0.62f, 0.63f, 0.67f)
            };
            Color tint = responder ? new Color(0.38f, 0.72f, 1f) : attendeePalette[seed % attendeePalette.Length];
            Material shared = target.sharedMaterial;
            if (shared == null) return;
            MaterialPropertyBlock properties = new MaterialPropertyBlock();
            target.GetPropertyBlock(properties);
            if (shared.HasProperty("_BaseColor")) properties.SetColor("_BaseColor", tint);
            else if (shared.HasProperty("_Color")) properties.SetColor("_Color", tint);
            target.SetPropertyBlock(properties);
        }

        public void SetMotion(Vector3 direction, bool isMoving)
        {
            moving = isMoving;
            if (isMoving) hasIdleFacing = false;
            if (animator != null && animatorWalking != isMoving)
            {
                animatorWalking = isMoving;
                animator.speed = isMoving ? walkingAnimationSpeed : 1f;
                animator.CrossFadeInFixedTime(isMoving ? WalkingState : IdleState, 0.15f);
            }
            direction.y = 0f;
            if (direction.sqrMagnitude > 0.0025f)
            {
                Quaternion facing = Quaternion.LookRotation(direction.normalized, Vector3.up);
                transform.rotation = Quaternion.Slerp(transform.rotation, facing, 1f - Mathf.Exp(-8f * Time.deltaTime));
            }
        }

        public void SetAnimationSpeed(float worldMovementSpeed)
        {
            walkingAnimationSpeed = Mathf.Clamp(worldMovementSpeed / 1.5f, 0.75f, 1.35f);
        }

        public void SetIdleFacing(float yaw)
        {
            idleFacing = Quaternion.Euler(0f, yaw, 0f);
            hasIdleFacing = true;
        }

        private void LateUpdate()
        {
            if (visualRoot == null) return;
            float bob = moving ? Mathf.Sin(Time.time * 8f + phase) * bobAmount : Mathf.Sin(Time.time * 1.7f + phase) * bobAmount * 0.2f;
            if (canBob) visualRoot.localPosition = visualBasePosition + Vector3.up * bob;
            if (!moving && hasIdleFacing)
            {
                transform.rotation = Quaternion.Slerp(transform.rotation, idleFacing,
                    1f - Mathf.Exp(-5f * Time.unscaledDeltaTime));
            }
        }

        private void OnDisable()
        {
            if (visualRoot != null) visualRoot.localPosition = visualBasePosition;
            moving = false;
            animatorWalking = false;
            hasIdleFacing = false;
            if (animator != null)
            {
                animator.speed = 1f;
                animator.Play(IdleState, 0, 0f);
            }
        }
    }
}
