using System;
using System.Collections.Generic;
using Aman.Models;

namespace Aman.Data
{
    public interface IDataSource
    {
        event Action<List<Cluster>> OnClustersUpdated;

        event Action<Incident> OnIncidentChanged;

        List<DevicePresence> GetCurrentPresences();

        SelectionDetail GetSelectionDetail(string entityId, string entityType);
    }
}
