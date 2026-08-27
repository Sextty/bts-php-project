'use client';

import { useCallback, useState, useEffect } from 'react';
import {
  UserX,
  Search,
  KeyRound,
  RefreshCw,
  UserCheck,
  ChevronLeft,
  ChevronRight,
} from 'lucide-react';
import {
  getSecurityUsers,
  suspendSecurityUser,
  unsuspendSecurityUser,
  revokeSecurityUserTokens,
  SecurityUsersData,
} from '@/lib/api/security';
import { formatDate } from '@/lib/utils';
import { getErrorMessage } from '@/lib/api/client';
import { StatusMessage } from '@/components/status-message';
import { AccessibleModal } from '@/components/accessible-modal';

export default function SecurityBannedUsersPage() {
  const [data, setData] = useState<SecurityUsersData | null>(null);
  const [tab, setTab] = useState<'customers' | 'staff'>('customers');
  const [search, setSearch] = useState('');
  const [appliedSearch, setAppliedSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [customersPage, setCustomersPage] = useState(1);
  const [staffPage, setStaffPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [suspendingUser, setSuspendingUser] = useState<{ id: number; name: string; email: string } | null>(null);
  const [suspendReason, setSuspendReason] = useState('');
  const [submittingAction, setSubmittingAction] = useState(false);
  const [actionMessage, setActionMessage] = useState<{ tone: 'error' | 'success'; text: string } | null>(null);
  const [confirmation, setConfirmation] = useState<{
    kind: 'unsuspend' | 'revoke';
    id: number;
    email: string;
    type: 'customer' | 'staff';
  } | null>(null);

  const fetchUsers = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await getSecurityUsers({
        search: appliedSearch || undefined,
        status: statusFilter || undefined,
        customers_page: customersPage,
        staff_page: staffPage,
      });
      setData(res);
    } catch (fetchError: unknown) {
      setError(getErrorMessage(fetchError));
    } finally {
      setLoading(false);
    }
  }, [appliedSearch, customersPage, staffPage, statusFilter]);

  useEffect(() => {
    queueMicrotask(() => void fetchUsers());
  }, [fetchUsers]);

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    if (customersPage === 1 && staffPage === 1 && appliedSearch === search) {
      void fetchUsers();
      return;
    }
    setCustomersPage(1);
    setStaffPage(1);
    setAppliedSearch(search);
  };

  const handleConfirmSuspend = async () => {
    if (!suspendingUser) return;
    if (!suspendReason.trim() || suspendReason.length < 3) {
      setActionMessage({ tone: 'error', text: 'Motif requis : au moins 3 caractères.' });
      return;
    }

    setSubmittingAction(true);
    try {
      await suspendSecurityUser(suspendingUser.id, suspendReason);
      setSuspendingUser(null);
      setSuspendReason('');
      setActionMessage({ tone: 'success', text: 'Compte suspendu et sessions révoquées.' });
      await fetchUsers();
    } catch (actionError: unknown) {
      setActionMessage({ tone: 'error', text: `Erreur de suspension : ${getErrorMessage(actionError, 'Échec')}` });
    } finally {
      setSubmittingAction(false);
    }
  };

  const executeConfirmation = async () => {
    if (!confirmation) return;
    setSubmittingAction(true);
    setActionMessage(null);
    try {
      if (confirmation.kind === 'unsuspend') {
        await unsuspendSecurityUser(confirmation.id);
        setActionMessage({ tone: 'success', text: 'Compte débloqué.' });
      } else {
        const result = await revokeSecurityUserTokens(confirmation.id, confirmation.type);
        setActionMessage({ tone: 'success', text: result.message });
      }
      setConfirmation(null);
      await fetchUsers();
    } catch (actionError: unknown) {
      setActionMessage({ tone: 'error', text: getErrorMessage(actionError, 'Action impossible.') });
    } finally {
      setSubmittingAction(false);
    }
  };

  const activeMeta = tab === 'customers' ? data?.customers.meta : data?.staff.meta;
  const activePage = tab === 'customers' ? customersPage : staffPage;

  return (
    <div className="sc-page">
      {/* ── Header ── */}
      <div className="sc-page-header flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <div className="flex items-center gap-2.5">
            <h1 className="text-xl font-bold text-[#0C1825] tracking-tight font-sans">
              Contrôle des Comptes & Révocation des Sessions
            </h1>
            <span className="px-2.5 py-0.5 text-xs font-semibold bg-[#FDF2F2] text-[#C0272D] border border-[#F5C2C4] rounded-full">
              Sécurité SOC
            </span>
          </div>
          <p className="text-xs text-[#3D5166] mt-1">
            Suspension immédiate des comptes compromis, déconnexion forcée et gestion des motifs de blocage
          </p>
        </div>

        <button
          onClick={() => void fetchUsers()}
          disabled={loading}
          className="p-2 rounded-xl bg-white border border-[#E0E4E9] hover:bg-[#F4F6F8] text-[#3D5166] transition-colors disabled:opacity-50 shadow-2xs"
        >
          <RefreshCw className={`size-3.5 ${loading ? 'animate-spin' : ''}`} />
        </button>
      </div>

      <StatusMessage message={error} />
      <StatusMessage message={actionMessage?.text} tone={actionMessage?.tone} />

      {/* ── Tabs & Filter Bar ── */}
      <div className="p-4 rounded-2xl bg-white border border-[#E0E4E9] shadow-xs flex flex-col md:flex-row gap-3 items-center justify-between text-xs">
        <div role="tablist" aria-label="Type de compte" className="flex items-center gap-2">
          <button
            type="button"
            role="tab"
            aria-selected={tab === 'customers'}
            onClick={() => setTab('customers')}
            className={`px-4 py-2 rounded-xl font-semibold transition-colors ${
              tab === 'customers'
                ? 'bg-[#C0272D] text-white shadow-xs'
                : 'bg-[#F4F6F8] text-[#3D5166] hover:text-[#0C1825]'
            }`}
          >
            Clients ({data?.customers.meta.total ?? 0})
          </button>
          <button
            type="button"
            role="tab"
            aria-selected={tab === 'staff'}
            onClick={() => setTab('staff')}
            className={`px-4 py-2 rounded-xl font-semibold transition-colors ${
              tab === 'staff'
                ? 'bg-[#C0272D] text-white shadow-xs'
                : 'bg-[#F4F6F8] text-[#3D5166] hover:text-[#0C1825]'
            }`}
          >
            Personnel Staff ({data?.staff.meta.total ?? 0})
          </button>
        </div>

        <div className="flex flex-wrap items-center gap-3 w-full md:w-auto">
          <form onSubmit={handleSearch} className="relative w-full md:w-64">
            <Search className="size-4 text-[#8C9BAE] absolute left-3.5 top-2.5" />
            <input
              type="text"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Nom, e-mail..."
              className="w-full pl-10 pr-3 py-2 bg-[#F8FAFC] border border-[#E0E4E9] rounded-xl text-[#0C1825] placeholder:text-[#8C9BAE] focus:outline-none focus:border-[#C0272D]"
            />
          </form>

          <select
            value={statusFilter}
            onChange={(e) => {
              setStatusFilter(e.target.value);
              setCustomersPage(1);
              setStaffPage(1);
            }}
            aria-label="Filtrer par statut"
            className="px-3 py-2 bg-white border border-[#E0E4E9] rounded-xl text-[#0C1825] focus:outline-none focus:border-[#C0272D]"
          >
            <option value="">Tous les statuts</option>
            <option value="active">Actif</option>
            <option value="suspended">Suspendu</option>
          </select>
        </div>
      </div>

      {/* ── Main Users Table ── */}
      <div className="bg-white border border-[#E0E4E9] rounded-2xl shadow-xs overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-left text-xs">
            <thead className="bg-[#F8FAFC] border-b border-[#E0E4E9] text-[#3D5166] uppercase text-[10px] font-bold tracking-wider">
              <tr>
                <th className="py-3 px-4">Utilisateur</th>
                <th className="py-3 px-4">E-mail</th>
                <th className="py-3 px-4">Statut</th>
                <th className="py-3 px-4">Sessions Actives</th>
                <th className="py-3 px-4">Motif / Date Suspension</th>
                <th className="py-3 px-4 text-right">Actions SOC</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-[#E0E4E9]">
              {loading && !data ? (
                <tr>
                  <td colSpan={6} className="py-12 text-center text-slate-500">
                    Chargement des utilisateurs...
                  </td>
                </tr>
              ) : tab === 'customers' ? (
                data?.customers.items.length === 0 ? (
                  <tr>
                    <td colSpan={6} className="py-12 text-center text-slate-500">
                      Aucune donnée
                    </td>
                  </tr>
                ) : (
                  data?.customers.items.map((u) => (
                    <tr key={u.id} className="hover:bg-[#F8FAFC] transition-colors">
                      <td className="py-3 px-4 font-semibold text-[#0C1825]">
                        {u.first_name} {u.last_name}
                      </td>
                      <td className="py-3 px-4 text-[#3D5166]">{u.email}</td>
                      <td className="py-3 px-4">
                        <span
                          className={`px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase ${
                            u.status === 'suspended'
                              ? 'bg-red-100 text-red-700'
                              : 'bg-emerald-100 text-emerald-700'
                          }`}
                        >
                          {u.status === 'suspended' ? 'SUSPENDU' : 'ACTIF'}
                        </span>
                      </td>
                      <td className="py-3 px-4">
                        <span className="font-bold text-[#0C1825]">{u.active_tokens_count}</span>
                        <span className="text-[#3D5166] text-[11px] ml-1">jeton(s)</span>
                      </td>
                      <td className="py-3 px-4 text-[#3D5166] max-w-[220px] truncate">
                        {u.status === 'suspended' ? (
                          <div>
                            <div className="text-red-700 font-semibold truncate">{u.banned_reason || 'Raison non spécifiée'}</div>
                            <div className="text-[10px] text-[#3D5166]">{formatDate(u.banned_at)}</div>
                          </div>
                        ) : (
                          <span className="text-slate-400">—</span>
                        )}
                      </td>
                      <td className="py-3 px-4 text-right">
                        <div className="flex items-center justify-end gap-1.5">
                          {u.active_tokens_count > 0 && (
                            <button
                              onClick={() => setConfirmation({ kind: 'revoke', id: u.id, email: u.email, type: 'customer' })}
                              disabled={submittingAction}
                              title="Révoquer tous les jetons actifs"
                              className="px-2.5 py-1 bg-amber-50 hover:bg-amber-100 text-amber-800 rounded-lg border border-amber-200 text-xs font-semibold flex items-center gap-1"
                            >
                              <KeyRound className="size-3.5" />
                              <span>Révoquer</span>
                            </button>
                          )}

                          {u.status === 'suspended' ? (
                            <button
                              onClick={() => setConfirmation({ kind: 'unsuspend', id: u.id, email: u.email, type: 'customer' })}
                              disabled={submittingAction}
                              className="px-2.5 py-1 bg-emerald-50 hover:bg-emerald-100 text-emerald-800 rounded-lg border border-emerald-200 text-xs font-semibold flex items-center gap-1"
                            >
                              <UserCheck className="size-3.5" />
                              <span>Débloquer</span>
                            </button>
                          ) : (
                            <button
                              onClick={() =>
                                setSuspendingUser({
                                  id: u.id,
                                  name: `${u.first_name} ${u.last_name}`,
                                  email: u.email,
                                })
                              }
                              disabled={submittingAction}
                              className="px-2.5 py-1 bg-red-50 hover:bg-red-100 text-red-700 rounded-lg border border-red-200 text-xs font-semibold flex items-center gap-1"
                            >
                              <UserX className="size-3.5" />
                              <span>Suspendre</span>
                            </button>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))
                )
              ) : data?.staff.items.length === 0 ? (
                <tr>
                  <td colSpan={6} className="py-12 text-center text-slate-500">
                    Aucune donnée
                  </td>
                </tr>
              ) : (
                data?.staff.items.map((s) => (
                  <tr key={s.id} className="hover:bg-[#F8FAFC] transition-colors">
                    <td className="py-3 px-4 font-semibold text-[#0C1825]">
                      {s.first_name} {s.last_name}
                      <span className="ml-2 px-2 py-0.5 bg-[#F4F6F8] text-[#3D5166] rounded text-[10px]">
                        {s.role}
                      </span>
                    </td>
                    <td className="py-3 px-4 text-[#3D5166]">{s.email}</td>
                    <td className="py-3 px-4">
                      <span
                        className={`px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase ${
                          s.status === 'suspended'
                            ? 'bg-red-100 text-red-700'
                            : 'bg-emerald-100 text-emerald-700'
                        }`}
                      >
                        {s.status === 'suspended' ? 'SUSPENDU' : 'ACTIF'}
                      </span>
                    </td>
                    <td className="py-3 px-4">
                      <span className="font-bold text-[#0C1825]">{s.active_tokens_count}</span>
                      <span className="text-[#3D5166] text-[11px] ml-1">jeton(s)</span>
                    </td>
                    <td className="py-3 px-4 text-[#3D5166]">
                      {s.branch ? `${s.branch.name} (${s.branch.ville})` : 'Global / Admin'}
                    </td>
                    <td className="py-3 px-4 text-right">
                      {s.active_tokens_count > 0 && (
                        <button
                          onClick={() => setConfirmation({ kind: 'revoke', id: s.id, email: s.email, type: 'staff' })}
                          disabled={submittingAction}
                          className="px-2.5 py-1 bg-amber-50 hover:bg-amber-100 text-amber-800 rounded-lg border border-amber-200 text-xs font-semibold inline-flex items-center gap-1"
                        >
                          <KeyRound className="size-3.5" />
                          <span>Révoquer</span>
                        </button>
                      )}
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
        {activeMeta && activeMeta.last_page > 1 && (
          <div className="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-4 py-3 text-xs text-slate-600">
            <span>Page {activeMeta.current_page} sur {activeMeta.last_page} · {activeMeta.total} comptes</span>
            <div className="flex items-center gap-2">
              <button
                type="button"
                aria-label="Page précédente"
                onClick={() => tab === 'customers' ? setCustomersPage((value) => Math.max(1, value - 1)) : setStaffPage((value) => Math.max(1, value - 1))}
                disabled={activePage <= 1 || loading}
                className="icon-button size-8"
              >
                <ChevronLeft className="size-4" />
              </button>
              <button
                type="button"
                aria-label="Page suivante"
                onClick={() => tab === 'customers' ? setCustomersPage((value) => Math.min(activeMeta.last_page, value + 1)) : setStaffPage((value) => Math.min(activeMeta.last_page, value + 1))}
                disabled={activePage >= activeMeta.last_page || loading}
                className="icon-button size-8"
              >
                <ChevronRight className="size-4" />
              </button>
            </div>
          </div>
        )}
      </div>

      {/* ── Suspend Modal with Mandatory Reason ── */}
      {suspendingUser && (
        <AccessibleModal
          open
          title="Suspendre le compte utilisateur"
          description={`${suspendingUser.name} (${suspendingUser.email})`}
          onClose={() => {
            setSuspendingUser(null);
            setSuspendReason('');
          }}
        >
          <div className="space-y-4 text-xs">
            <p className="text-[#3D5166]">
              Cette action bloque immédiatement l’accès au compte et <strong className="text-red-700">révoque toutes les sessions et jetons actifs</strong>. Un motif d’audit obligatoire est exigé.
            </p>

            <div>
              <label htmlFor="suspend-reason" className="block text-[#0C1825] font-semibold mb-1">
                Motif de la suspension (Audit SOC obligatoire) :
              </label>
              <textarea
                id="suspend-reason"
                rows={3}
                required
                value={suspendReason}
                onChange={(e) => setSuspendReason(e.target.value)}
                placeholder="Ex: Activité suspecte détectée, requêtes anormales..."
                className="w-full p-3 bg-[#F8FAFC] border border-[#E0E4E9] rounded-xl text-[#0C1825] placeholder:text-[#8C9BAE] focus:outline-none focus:border-[#C0272D]"
              ></textarea>
            </div>

            <div className="flex items-center justify-end gap-2 pt-2">
              <button
                type="button"
                onClick={() => {
                  setSuspendingUser(null);
                  setSuspendReason('');
                }}
                className="px-4 py-2 bg-[#F4F6F8] hover:bg-[#E0E4E9] text-[#0C1825] font-semibold rounded-xl"
              >
                Annuler
              </button>
              <button
                type="button"
                onClick={handleConfirmSuspend}
                disabled={submittingAction || suspendReason.trim().length < 3}
                className="px-4 py-2 bg-[#C0272D] hover:bg-[#A01F24] text-white font-bold rounded-xl disabled:opacity-50"
              >
                {submittingAction ? 'Suspension...' : 'Confirmer la Suspension'}
              </button>
            </div>
          </div>
        </AccessibleModal>
      )}

      <AccessibleModal
        open={confirmation !== null}
        title={confirmation?.kind === 'unsuspend' ? 'Débloquer ce compte ?' : 'Révoquer toutes les sessions ?'}
        description={confirmation?.email}
        onClose={() => setConfirmation(null)}
      >
        <div className="space-y-5 text-sm text-slate-600">
          <p>
            {confirmation?.kind === 'unsuspend'
              ? 'Le compte retrouvera immédiatement son accès.'
              : 'Tous les jetons actifs seront supprimés. L’utilisateur sera immédiatement déconnecté.'}
          </p>
          <div className="flex justify-end gap-2">
            <button type="button" className="secondary-button" onClick={() => setConfirmation(null)}>Annuler</button>
            <button type="button" className="primary-button" disabled={submittingAction} onClick={() => void executeConfirmation()}>
              {submittingAction ? 'Traitement…' : 'Confirmer'}
            </button>
          </div>
        </div>
      </AccessibleModal>
    </div>
  );
}
