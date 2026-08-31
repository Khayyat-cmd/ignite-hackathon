import { useEffect, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiRequest, ApiError } from '../api/client';

const unwrap = (page) => Array.isArray(page?.data) ? page.data : [];
const VENUE_PREFIX = 'aman-stadium-v1:';

export function useOperations(token) {
  const queryClient = useQueryClient();
  const [notice, setNotice] = useState('');
  const [actionError, setActionError] = useState('');
  const [automaticRecommendations, setAutomaticRecommendations] = useState(true);
  const [automaticError, setAutomaticError] = useState('');

  const query = useQuery({
    queryKey: ['operations', token],
    enabled: Boolean(token),
    staleTime: 2_000,
    retry: false,
    refetchIntervalInBackground: false,
    refetchInterval: (state) =>
      state.state.error instanceof ApiError && [401, 403].includes(state.state.error.status)
        ? false
        : Math.min(30_000, 3_000 * (2 ** Math.min(state.state.fetchFailureCount, 3))),
    queryFn: async ({ signal }) => {
      const [zones, responders, incidents, events, integrations] = await Promise.all([
        apiRequest(token, '/zones', { signal }),
        apiRequest(token, '/responders', { signal }),
        apiRequest(token, '/incidents', { signal }),
        apiRequest(token, '/events?after=0&limit=100', { signal }),
        apiRequest(token, '/integrations', { signal }),
      ]);
      const venueZones = unwrap(zones).filter((zone) => zone.demo_key?.startsWith(VENUE_PREFIX));
      const zoneIds = new Set(venueZones.map((zone) => zone.id));
      const venueResponders = unwrap(responders).filter((responder) => responder.demo_key?.startsWith(VENUE_PREFIX));
      const responderIds = new Set(venueResponders.map((responder) => responder.id));
      const venueIncidents = unwrap(incidents).filter((incident) => zoneIds.has(incident.zone_id));
      const incidentIds = new Set(venueIncidents.map((incident) => incident.id));
      return {
        zones: venueZones,
        responders: venueResponders,
        incidents: venueIncidents,
        events: (Array.isArray(events?.data) ? events.data : [])
          .filter((event) => zoneIds.has(event.zoneId) || incidentIds.has(event.incidentId) || responderIds.has(event.data?.responderId))
          .slice(-25).reverse(),
        integrations,
      };
    },
  });

  const mutation = useMutation({
    retry: false,
    mutationFn: ({ operation }) => operation(),
    onMutate: () => { setNotice(''); setActionError(''); },
    onSuccess: (_result, variables) => setNotice(variables.success),
    onError: (error) => setActionError(error.message),
    onSettled: () => queryClient.invalidateQueries({ queryKey: ['operations', token] }),
  });

  const post = (path, body) => apiRequest(token, path, { method: 'POST', ...(body === undefined ? {} : { body }) });
  const run = (operation, success) => mutation.mutate({ operation, success });
  const attempted = useRef(new Map());
  const recommending = useRef(false);

  useEffect(() => {
    if (!automaticRecommendations || query.isError || mutation.isPending || recommending.current) return;
    if (!query.data?.responders.some((responder) => responder.networkFreshness === 'fresh')) return;
    const incident = query.data.incidents.find((item) =>
      item.status === 'detected' && Date.now() - (attempted.current.get(item.id) || 0) > 30_000);
    if (!incident) return;
    const controller = new AbortController();
    attempted.current.set(incident.id, Date.now());
    recommending.current = true;
    setAutomaticError('');
    apiRequest(token, `/incidents/${incident.id}/recommend`, { method: 'POST', signal: controller.signal })
      .then(() => queryClient.invalidateQueries({ queryKey: ['operations', token] }))
      .catch((error) => { if (!controller.signal.aborted) setAutomaticError(error.message); })
      .finally(() => { recommending.current = false; });
    return () => controller.abort();
  }, [automaticRecommendations, mutation.isPending, query.data, query.isError, queryClient, token]);

  return {
    query, run, post, busy: mutation.isPending, notice, actionError, automaticError,
    automaticRecommendations, setAutomaticRecommendations,
  };
}
