import { useCallback, useEffect, useState } from 'react';
import { apiRequest } from './api/client';

function storedSession() {
  try { return JSON.parse(sessionStorage.getItem('aman_session') || 'null'); }
  catch { sessionStorage.removeItem('aman_session'); return null; }
}

function Field({ label, ...props }) {
  return <label className="field"><span>{label}</span><input {...props} /></label>;
}

function Access({ onAuthenticated }) {
  const invitationToken = new URLSearchParams(window.location.search).get('token') || '';
  const [mode, setMode] = useState(invitationToken ? 'accept' : 'login');
  const [form, setForm] = useState(invitationToken ? { token: invitationToken } : {});
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const changeMode = (next) => { setMode(next); setForm(next === 'accept' && invitationToken ? { token: invitationToken } : {}); setError(''); };
  const update = (event) => setForm((current) => ({ ...current, [event.target.name]: event.target.value }));
  const submit = async (event) => {
    event.preventDefault();
    setBusy(true);
    setError('');
    try {
      const path = mode === 'accept' ? '/auth/invitations/accept' : `/auth/${mode}`;
      const result = await apiRequest(path, { method: 'POST', body: form });
      if (!result.token) setError(result.message || 'This organization is awaiting approval.');
      else onAuthenticated(result);
    } catch (requestError) { setError(requestError.message); }
    finally { setBusy(false); }
  };

  return <main className="access"><section className="auth-card">
    <p className="eyebrow">AMAN backend test</p><h1>{mode === 'login' ? 'Sign in' : mode === 'register' ? 'Create organization' : 'Accept invitation'}</h1>
    <div className="tabs"><button className={mode === 'login' ? 'active' : ''} onClick={() => changeMode('login')}>Login</button><button className={mode === 'register' ? 'active' : ''} onClick={() => changeMode('register')}>Register</button><button className={mode === 'accept' ? 'active' : ''} onClick={() => changeMode('accept')}>Invitation</button></div>
    <form onSubmit={submit}>
      {mode === 'register' && <><Field label="Organization name" name="organizationName" value={form.organizationName || ''} onChange={update} required /><Field label="Owner name" name="name" value={form.name || ''} onChange={update} required /></>}
      {mode === 'accept' && <><Field label="Invitation token" name="token" value={form.token || ''} onChange={update} minLength="64" maxLength="64" required /><Field label="Your name" name="name" value={form.name || ''} onChange={update} required /></>}
      {mode !== 'accept' && <Field label="Work email" name="email" type="email" autoComplete="email" value={form.email || ''} onChange={update} required />}
      <Field label="Password" name="password" type="password" minLength="10" autoComplete={mode === 'login' ? 'current-password' : 'new-password'} value={form.password || ''} onChange={update} required />
      {mode !== 'login' && <Field label="Confirm password" name="password_confirmation" type="password" minLength="10" autoComplete="new-password" value={form.password_confirmation || ''} onChange={update} required />}
      {error && <p className="message error" role="alert">{error}</p>}
      <button className="primary" disabled={busy}>{busy ? 'Please wait…' : mode === 'login' ? 'Sign in' : mode === 'register' ? 'Create and sign in' : 'Join organization'}</button>
    </form>
  </section></main>;
}

function Dashboard({ session, token, onLogout }) {
  const [data, setData] = useState(null);
  const [events, setEvents] = useState([]);
  const [members, setMembers] = useState([]);
  const [eventForm, setEventForm] = useState({ name: '', venueName: '' });
  const [inviteForm, setInviteForm] = useState({ email: '', role: 'operator' });
  const [invitationMessage, setInvitationMessage] = useState('');
  const [error, setError] = useState('');
  const canManage = ['owner', 'admin'].includes(session.user.role);

  const load = useCallback(async () => {
    setError('');
    try {
      const [summary, eventResult, memberResult] = await Promise.all([
        apiRequest('/organization/dashboard', { token }),
        apiRequest('/organization/events', { token }),
        canManage ? apiRequest('/organization/members', { token }) : Promise.resolve({ data: { data: [] } }),
      ]);
      setData(summary); setEvents(eventResult.data.data); setMembers(memberResult.data.data);
    } catch (requestError) { setError(requestError.message); }
  }, [canManage, token]);
  useEffect(() => { load(); }, [load]);

  const createEvent = async (event) => {
    event.preventDefault();
    try { await apiRequest('/organization/events', { token, method: 'POST', body: eventForm }); setEventForm({ name: '', venueName: '' }); await load(); }
    catch (requestError) { setError(requestError.message); }
  };
  const invite = async (event) => {
    event.preventDefault();
    try {
      const result = await apiRequest('/organization/invitations', { token, method: 'POST', body: inviteForm });
      setInvitationMessage(`Invitation emailed to ${result.invitation.email}.`); setInviteForm({ email: '', role: 'operator' });
    } catch (requestError) { setError(requestError.message); }
  };

  return <main className="dashboard">
    <header><div><p className="eyebrow">{session.organization.name}</p><h1>Owner dashboard</h1></div><div><span>{session.user.name} · {session.user.role}</span><button onClick={onLogout}>Sign out</button></div></header>
    {error && <p className="message error" role="alert">{error}</p>}
    <section className="stats">{Object.entries(data?.counts || {}).map(([label, value]) => <article key={label}><strong>{value}</strong><span>{label.replace(/([A-Z])/g, ' $1')}</span></article>)}</section>
    <div className="grid">
      <section className="panel"><h2>Events</h2>{!events.length && <p className="muted">No events yet.</p>}{events.map((item) => <div className="row" key={item.id}><span><strong>{item.name}</strong><small>{item.venue_name}</small></span><b>{item.status}</b></div>)}
        {canManage && <form onSubmit={createEvent}><h3>Create event</h3><Field label="Event name" value={eventForm.name} onChange={(e) => setEventForm({ ...eventForm, name: e.target.value })} required /><Field label="Venue" value={eventForm.venueName} onChange={(e) => setEventForm({ ...eventForm, venueName: e.target.value })} required /><button className="primary">Create</button></form>}
      </section>
      <section className="panel"><h2>Members</h2>{members.map((member) => <div className="row" key={member.id}><span><strong>{member.name}</strong><small>{member.email}</small></span><b>{member.role}</b></div>)}
        {canManage && <form onSubmit={invite}><h3>Invite member</h3><Field label="Email" type="email" value={inviteForm.email} onChange={(e) => setInviteForm({ ...inviteForm, email: e.target.value })} required /><label className="field"><span>Role</span><select value={inviteForm.role} onChange={(e) => setInviteForm({ ...inviteForm, role: e.target.value })}><option>operator</option><option>admin</option><option>responder</option><option>viewer</option></select></label><button className="primary">Invite</button>{invitationMessage && <p className="message token">{invitationMessage}</p>}</form>}
      </section>
    </div><p className="note">Temporary interface for testing authentication and organization administration only.</p>
  </main>;
}

export default function App() {
  const [token, setToken] = useState(() => sessionStorage.getItem('aman_token') || '');
  const [session, setSession] = useState(storedSession);
  const authenticate = (result) => { sessionStorage.setItem('aman_token', result.token); sessionStorage.setItem('aman_session', JSON.stringify(result)); setToken(result.token); setSession(result); };
  const logout = async () => {
    try { await apiRequest('/auth/logout', { token, method: 'POST' }); } catch { /* Clear local access even if backend is offline. */ }
    sessionStorage.removeItem('aman_token'); sessionStorage.removeItem('aman_session'); setToken(''); setSession(null);
  };
  return token && session ? <Dashboard session={session} token={token} onLogout={logout} /> : <Access onAuthenticated={authenticate} />;
}
