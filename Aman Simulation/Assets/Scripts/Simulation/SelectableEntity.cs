using UnityEngine;

namespace Aman.Simulation
{
    public class SelectableEntity : MonoBehaviour
    {
        public string Id { get; private set; }
        public string EntityType { get; private set; }
        
        private Renderer[] renderers;
        private MaterialPropertyBlock[] originalProperties;
        private bool canHighlight;

        public void Initialize(string id, string entityType)
        {
            Id = id;
            EntityType = entityType;

            renderers = GetComponentsInChildren<Renderer>(true);
            originalProperties = new MaterialPropertyBlock[renderers.Length];
            for (int i = 0; i < renderers.Length; i++)
            {
                originalProperties[i] = new MaterialPropertyBlock();
                renderers[i].GetPropertyBlock(originalProperties[i]);
            }
            canHighlight = renderers.Length > 0;
        }

        public void SetHighlight(bool active)
        {
            if (!canHighlight) return;
            for (int i = 0; i < renderers.Length; i++)
            {
                if (renderers[i] == null) continue;
                if (!active)
                {
                    renderers[i].SetPropertyBlock(originalProperties[i]);
                    continue;
                }

                MaterialPropertyBlock highlighted = new MaterialPropertyBlock();
                renderers[i].GetPropertyBlock(highlighted);
                highlighted.SetColor("_BaseColor", Color.yellow);
                highlighted.SetColor("_Color", Color.yellow);
                highlighted.SetColor("_EmissionColor", Color.yellow * 0.35f);
                renderers[i].SetPropertyBlock(highlighted);
            }
        }
    }
}
