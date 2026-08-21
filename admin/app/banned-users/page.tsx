'use client';

import { useEffect, useState, useCallback, useTransition } from 'react';
import { useRouter } from 'next/navigation';
import {
  UserX,
  Search,
  CheckCircle2,
  ShieldAlert,
  Loader2,
  LockOpen,
  Mail,
  Phone,
  Calendar,
  Shield,
  RefreshCw,
  UserCheck,
} from 'lucide-react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { PageLoading } from '@/components/page-loading';
import { ErrorAlert } from '@/components/error-alert';
import {
  listBannedUsers,
  unbanUser,
  type BannedUserDto,
} from '@/lib/api/reports';
import { getStaffToken } from '@/lib/auth/staff-token';
import { cn } from '@/lib/utils';

export default function BannedUsersPage() {
  const router = useRouter();
  const [isPending, startTransition] = useTransition();

  const [bannedUsers, setBannedUsers] = useState<BannedUserDto[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [totalCount, setTotalCount] = useState(0);
  const [error, setError] = useState<string | null>(null);

  // Unban confirmation dialog
  const [unbanModalOpen, setUnbanModalOpen] = useState(false);
  const [selectedUser, setSelectedUser] = useState<BannedUserDto | null>(null);
  const [unbanning, setUnbanning] = useState(false);
  const [unbanSuccess, setUnbanSuccess] = useState<string | null>(null);

  const fetchBannedUsers = useCallback(async (searchQuery: string, pageNum: number) => {
    setLoading(true);
    setError(null);
    try {
      const res = await listBannedUsers(searchQuery, pageNum);
      setBannedUsers(res.banned_users || []);
      setTotalPages(res.meta?.last_page || 1);
      setTotalCount(res.meta?.total || 0);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Erreur lors du chargement des clients bannis.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    if (!getStaffToken()) {
      router.replace('/login');
      return;
    }
    fetchBannedUsers(search, page);
  }, [fetchBannedUsers, router, page, search]);

  const handleSearchChange = (val: string) => {
    setSearch(val);
    setPage(1);
  };

  const handleConfirmUnban = async () => {
    if (!selectedUser) return;
    setUnbanning(true);
    try {
      await unbanUser(selectedUser.id);
      setUnbanSuccess(`Le compte de ${selectedUser.name} a été réactivé avec succès !`);
      setTimeout(() => {
        setUnbanModalOpen(false);
        setSelectedUser(null);
        setUnbanSuccess(null);
        fetchBannedUsers(search, page);
      }, 1200);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Impossible de débloquer le client.');
    } finally {
      setUnbanning(false);
    }
  };

  if (loading && bannedUsers.length === 0) {
    return <PageLoading />;
  }

  return (
    <div className="px-4 sm:px-8 py-8 max-w-7xl mx-auto space-y-6">
      {/* ── Header ── */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <div className="flex items-center gap-2">
            <div className="size-9 rounded-xl bg-red-100 flex items-center justify-center text-[#C0272D]">
              <UserX className="size-5" />
            </div>
            <div>
              <h1 className="text-xl font-bold text-[#0C1825]">Gestion des Bannissements</h1>
              <p className="text-xs text-[#3D5166]">
                Consultez la liste des clients suspendus et débloquez leurs accès bancaires en un clic.
              </p>
            </div>
          </div>
        </div>

        <button
          type="button"
          onClick={() => fetchBannedUsers(search, page)}
          className="btn-outline text-xs inline-flex items-center gap-1.5 self-start sm:self-auto"
        >
          <RefreshCw className="size-3.5" />
          <span>Actualiser</span>
        </button>
      </div>

      <ErrorAlert message={error} />

      {/* ── Summary & Filter Bar ── */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div className="p-4 rounded-2xl bg-white border border-[#E0E4E9] shadow-xs flex items-center gap-3">
          <div className="size-10 rounded-xl bg-red-50 border border-red-200 flex items-center justify-center text-[#C0272D]">
            <ShieldAlert className="size-5" />
          </div>
          <div>
            <span className="text-xl font-bold text-[#0C1825]">{totalCount}</span>
            <span className="text-xs text-[#3D5166] block">Comptes actuellement suspendus</span>
          </div>
        </div>

        <div className="sm:col-span-2 flex items-center bg-white rounded-2xl border border-[#E0E4E9] px-3 shadow-xs">
          <Search className="size-4 text-[#3D5166] shrink-0" />
          <input
            type="text"
            value={search}
            onChange={(e) => handleSearchChange(e.target.value)}
            placeholder="Rechercher par nom, prénom, email, téléphone ou CIN…"
            className="w-full text-xs px-3 py-3 bg-transparent text-[#0C1825] placeholder:text-[#3D5166]/50 focus:outline-none"
          />
        </div>
      </div>

      {/* ── Banned Users Table ── */}
      <div className="bg-white rounded-2xl border border-[#E0E4E9] shadow-xs overflow-hidden">
        {bannedUsers.length === 0 ? (
          <div className="py-16 text-center space-y-3">
            <div className="size-12 rounded-full bg-emerald-100 text-emerald-600 mx-auto flex items-center justify-center">
              <UserCheck className="size-6" />
            </div>
            <p className="text-sm font-bold text-[#0C1825]">Aucun client banni pour le moment</p>
            <p className="text-xs text-[#3D5166] max-w-sm mx-auto">
              Tous les comptes clients sont actuellement actifs. Les utilisateurs suspendus via le chat ou le panneau de modération apparaîtront ici.
            </p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs border-collapse">
              <thead>
                <tr className="border-b border-[#E0E4E9] bg-[#F4F6F8] text-[11px] font-bold text-[#3D5166] uppercase tracking-wider">
                  <th className="py-3 px-4">Demandeur / Client</th>
                  <th className="py-3 px-4">Contact</th>
                  <th className="py-3 px-4">Date de Bannissement</th>
                  <th className="py-3 px-4">Motif de Suspension</th>
                  <th className="py-3 px-4">Suspendu Par</th>
                  <th className="py-3 px-4 text-right">Action</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-[#E0E4E9]">
                {bannedUsers.map((user) => (
                  <tr key={user.id} className="hover:bg-[#FAFBFD] transition-colors">
                    {/* 1. Applicant */}
                    <td className="py-3.5 px-4">
                      <div className="flex items-center gap-2.5">
                        <div className="size-8 rounded-full bg-red-100 text-[#C0272D] flex items-center justify-center font-bold text-xs shrink-0">
                          {user.first_name?.[0]?.toUpperCase() || 'U'}
                        </div>
                        <div>
                          <span className="font-bold text-[#0C1825] block">{user.name}</span>
                          <span className="text-[10px] text-[#3D5166]">CIN : {user.cin}</span>
                        </div>
                      </div>
                    </td>

                    {/* 2. Contact */}
                    <td className="py-3.5 px-4 space-y-1">
                      <div className="flex items-center gap-1.5 text-[#3D5166]">
                        <Mail className="size-3 text-[#3D5166]/70" />
                        <span className="truncate max-w-[180px]">{user.email}</span>
                      </div>
                      {user.phone && (
                        <div className="flex items-center gap-1.5 text-[#3D5166]">
                          <Phone className="size-3 text-[#3D5166]/70" />
                          <span>{user.phone}</span>
                        </div>
                      )}
                    </td>

                    {/* 3. Banned Date */}
                    <td className="py-3.5 px-4 text-[#3D5166]">
                      <div className="flex items-center gap-1.5">
                        <Calendar className="size-3 text-[#3D5166]/70" />
                        <span>
                          {user.banned_at
                            ? new Date(user.banned_at).toLocaleDateString('fr-FR', {
                                day: '2-digit',
                                month: 'short',
                                year: 'numeric',
                                hour: '2-digit',
                                minute: '2-digit',
                              })
                            : 'N/A'}
                        </span>
                      </div>
                    </td>

                    {/* 4. Reason */}
                    <td className="py-3.5 px-4 max-w-xs">
                      <div className="p-2 rounded-lg bg-red-50 border border-red-200/60 text-red-900 text-[11px] leading-relaxed">
                        {user.banned_reason || 'Suspension administrative'}
                      </div>
                    </td>

                    {/* 5. Banned by */}
                    <td className="py-3.5 px-4 text-[#3D5166]">
                      {user.banned_by ? (
                        <div className="flex items-center gap-1.5">
                          <Shield className="size-3 text-[#C0272D]" />
                          <div>
                            <span className="font-semibold text-[#0C1825] block">{user.banned_by.name}</span>
                            <span className="text-[10px] text-[#3D5166]">{user.banned_by.role}</span>
                          </div>
                        </div>
                      ) : (
                        <span className="text-[#3D5166]/50">—</span>
                      )}
                    </td>

                    {/* 6. Action */}
                    <td className="py-3.5 px-4 text-right">
                      <button
                        type="button"
                        onClick={() => {
                          setSelectedUser(user);
                          setUnbanModalOpen(true);
                        }}
                        className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-emerald-50 border border-emerald-300 text-emerald-800 text-xs font-semibold hover:bg-emerald-100 transition-colors shadow-2xs"
                      >
                        <LockOpen className="size-3.5 text-emerald-600" />
                        <span>Débloquer</span>
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {/* ── Pagination ── */}
        {totalPages > 1 && (
          <div className="flex items-center justify-between px-4 py-3 border-t border-[#E0E4E9] bg-[#F4F6F8] text-xs">
            <span className="text-[#3D5166]">
              Page {page} sur {totalPages} ({totalCount} résultat(s))
            </span>
            <div className="flex items-center gap-2">
              <button
                type="button"
                onClick={() => setPage((p) => Math.max(1, p - 1))}
                disabled={page <= 1}
                className="btn-outline text-xs px-2.5 py-1 disabled:opacity-40"
              >
                Précédent
              </button>
              <button
                type="button"
                onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
                disabled={page >= totalPages}
                className="btn-outline text-xs px-2.5 py-1 disabled:opacity-40"
              >
                Suivant
              </button>
            </div>
          </div>
        )}
      </div>

      {/* ── Unban Modal ── */}
      <Dialog open={unbanModalOpen} onOpenChange={setUnbanModalOpen}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle className="font-display text-lg text-[#0C1825] flex items-center gap-2">
              <LockOpen className="size-5 text-emerald-600" />
              Débloquer le compte client
            </DialogTitle>
            <DialogDescription className="text-xs text-[#3D5166] pt-1">
              Êtes-vous sûr de vouloir lever la suspension du compte de{' '}
              <strong className="text-[#0C1825]">{selectedUser?.name}</strong> ({selectedUser?.email}) ?
            </DialogDescription>
          </DialogHeader>

          {unbanSuccess ? (
            <div className="p-3 bg-emerald-50 border border-emerald-200 rounded-xl text-xs text-emerald-800 flex items-center gap-2 font-medium">
              <CheckCircle2 className="size-4 shrink-0 text-emerald-600" />
              <span>{unbanSuccess}</span>
            </div>
          ) : (
            <div className="p-3 bg-amber-50 border border-amber-200 rounded-xl text-xs text-amber-900 space-y-1">
              <p className="font-bold">Conséquences du déblocage :</p>
              <ul className="list-disc list-inside space-y-0.5 text-[11px]">
                <li>Le client pourra à nouveau se connecter avec ses identifiants.</li>
                <li>Il recevra une notification confirmant la réactivation de son compte.</li>
                <li>L&apos;action sera enregistrée dans le journal d&apos;audit.</li>
              </ul>
            </div>
          )}

          <DialogFooter className="gap-2 pt-3 border-t border-[#E0E4E9]">
            <button
              type="button"
              onClick={() => setUnbanModalOpen(false)}
              className="btn-outline text-xs"
              disabled={unbanning}
            >
              Annuler
            </button>
            <button
              type="button"
              onClick={handleConfirmUnban}
              disabled={unbanning || !!unbanSuccess}
              className="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold inline-flex items-center gap-1.5 transition-colors"
            >
              {unbanning ? (
                <>
                  <Loader2 className="size-3.5 animate-spin" />
                  <span>Déblocage…</span>
                </>
              ) : (
                <>
                  <CheckCircle2 className="size-3.5" />
                  <span>Confirmer le Déblocage</span>
                </>
              )}
            </button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
