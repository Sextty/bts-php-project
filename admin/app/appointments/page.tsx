'use client';

import { useEffect, useState, useCallback, useMemo } from 'react';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
import {
  Calendar,
  Building2,
  Clock,
  MapPin,
  Phone,
  Printer,
  Search,
  Filter,
  CheckCircle2,
  AlertCircle,
  XCircle,
  ExternalLink,
  ChevronRight,
  RefreshCw,
  MessageSquare,
  FileText,
  User,
  Shield,
  Layers,
  Sparkles,
  Navigation,
  Globe,
  ArrowUpDown,
} from 'lucide-react';
import { StaffHeader } from '@/components/staff-header';
import { ErrorAlert } from '@/components/error-alert';
import { PageLoading } from '@/components/page-loading';
import {
  listStaffAppointments,
  getBranchesOverview,
  type AppointmentItemDto,
  type AppointmentStatsDto,
  type BranchOverviewDto,
} from '@/lib/api/appointments-management';
import { ApiError } from '@/lib/api/client';
import { getStaffToken } from '@/lib/auth/staff-token';
import { cn } from '@/lib/utils';

type ActiveTab = 'appointments' | 'branches';

export default function AdminAppointmentsPage() {
  const router = useRouter();

  // Tab State
  const [activeTab, setActiveTab] = useState<ActiveTab>('appointments');

  // Appointments Data State
  const [appointments, setAppointments] = useState<AppointmentItemDto[]>([]);
  const [stats, setStats] = useState<AppointmentStatsDto>({
    total: 0,
    accepted: 0,
    proposed: 0,
    rejected: 0,
    today: 0,
    upcoming: 0,
  });
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [totalAppointments, setTotalAppointments] = useState(0);

  // Filters State for Appointments
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('all');
  const [branchFilter, setBranchFilter] = useState<string>('all');
  const [dateFilter, setDateFilter] = useState('all');

  // Branches Data State
  const [branches, setBranches] = useState<BranchOverviewDto[]>([]);
  const [branchSearch, setBranchSearch] = useState('');

  // UI States
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Fetch Appointments
  const fetchAppointments = useCallback(async (isRefresh = false) => {
    if (!getStaffToken()) {
      router.replace('/login');
      return;
    }
    if (isRefresh) setRefreshing(true);

    try {
      const res = await listStaffAppointments({
        page,
        per_page: 20,
        search: search.trim() || undefined,
        status: statusFilter !== 'all' ? statusFilter : undefined,
        branch_id: branchFilter !== 'all' ? branchFilter : undefined,
        date: dateFilter !== 'all' ? dateFilter : undefined,
      });

      setAppointments(res.appointments || []);
      setStats(res.stats || { total: 0, accepted: 0, proposed: 0, rejected: 0, today: 0, upcoming: 0 });
      setTotalPages(res.meta?.last_page || 1);
      setTotalAppointments(res.meta?.total || 0);
      setError(null);
    } catch (err: unknown) {
      setError(err instanceof ApiError ? err.message : 'Erreur lors du chargement des rendez-vous.');
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, [page, search, statusFilter, branchFilter, dateFilter, router]);

  // Fetch Branches Overview
  const fetchBranches = useCallback(async () => {
    try {
      const res = await getBranchesOverview();
      setBranches(res.branches || []);
    } catch (err: unknown) {
      console.error('Failed to load branches overview', err);
    }
  }, []);

  useEffect(() => {
    fetchAppointments();
  }, [fetchAppointments]);

  useEffect(() => {
    fetchBranches();
  }, [fetchBranches]);

  // Filtered branches for Tab 2
  const filteredBranches = useMemo(() => {
    if (!branchSearch.trim()) return branches;
    const q = branchSearch.toLowerCase();
    return branches.filter(
      (b) =>
        b.name.toLowerCase().includes(q) ||
        (b.ville && b.ville.toLowerCase().includes(q)) ||
        (b.address && b.address.toLowerCase().includes(q)) ||
        (b.phone && b.phone.includes(q))
    );
  }, [branches, branchSearch]);

  const handleFilterBranchClick = (branchId: number) => {
    setBranchFilter(String(branchId));
    setActiveTab('appointments');
    setPage(1);
  };

  const formatFrenchDate = (dateStr?: string | null) => {
    if (!dateStr) return 'Non définie';
    try {
      const d = new Date(dateStr);
      return d.toLocaleDateString('fr-FR', {
        weekday: 'short',
        day: '2-digit',
        month: 'short',
        year: 'numeric',
      });
    } catch {
      return dateStr;
    }
  };

  return (
    <div className="min-h-screen bg-[#F4F6F8]">
      <StaffHeader role="admin" />

      <main className="mx-auto max-w-7xl px-4 py-8 sm:px-8 space-y-8">
        {/* Header Title Section */}
        <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
          <div>
            <div className="flex items-center gap-2 mb-1">
              <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-[#FDF2F2] text-[#C0272D] border border-[#FECACA]">
                <Sparkles className="size-3" />
                Administration Centrale BTS Bank
              </span>
            </div>
            <h1 className="font-display text-2xl font-bold tracking-tight text-[#0C1825] sm:text-3xl">
              Rendez-vous & Réseau des Agences
            </h1>
            <p className="text-xs sm:text-sm text-[#3D5166] mt-1">
              Consultez tous les créneaux de rendez-vous fixés et explorez les 28 agences bancaires régionales BTS.
            </p>
          </div>

          <div className="flex items-center gap-2.5">
            <button
              type="button"
              onClick={() => {
                fetchAppointments(true);
                fetchBranches();
              }}
              disabled={refreshing}
              className="btn-outline text-xs inline-flex items-center gap-1.5 px-3 py-2 bg-white"
            >
              <RefreshCw className={cn('size-3.5', refreshing && 'animate-spin')} />
              <span>Actualiser</span>
            </button>
          </div>
        </div>

        {error && <ErrorAlert message={error} />}

        {/* Tab Navigation */}
        <div className="flex border-b border-[#E0E4E9] gap-4">
          <button
            type="button"
            onClick={() => setActiveTab('appointments')}
            className={cn(
              'flex items-center gap-2 pb-3 text-sm font-semibold border-b-2 transition-colors relative',
              activeTab === 'appointments'
                ? 'border-[#C0272D] text-[#C0272D]'
                : 'border-transparent text-[#3D5166] hover:text-[#0C1825]'
            )}
          >
            <Calendar className="size-4" />
            <span>Table des Rendez-vous</span>
            <span className="px-2 py-0.5 text-xs rounded-full bg-red-100 text-[#C0272D] font-bold">
              {stats.total}
            </span>
          </button>

          <button
            type="button"
            onClick={() => setActiveTab('branches')}
            className={cn(
              'flex items-center gap-2 pb-3 text-sm font-semibold border-b-2 transition-colors relative',
              activeTab === 'branches'
                ? 'border-[#C0272D] text-[#C0272D]'
                : 'border-transparent text-[#3D5166] hover:text-[#0C1825]'
            )}
          >
            <Building2 className="size-4" />
            <span>Réseau des 28 Agences BTS</span>
            <span className="px-2 py-0.5 text-xs rounded-full bg-gray-100 text-gray-700 font-bold">
              {branches.length || 28}
            </span>
          </button>
        </div>

        {/* TAB 1: APPOINTMENTS VIEW */}
        {activeTab === 'appointments' && (
          <div className="space-y-6 animate-in fade-in duration-200">
            {/* KPI Metric Cards */}
            <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
              <div className="bg-white p-4 rounded-xl border border-[#E0E4E9] shadow-xs">
                <div className="text-[11px] font-medium text-gray-500 uppercase tracking-wider">Total RDV</div>
                <div className="text-2xl font-bold text-[#0C1825] mt-1">{stats.total}</div>
                <div className="text-[10px] text-gray-400 mt-1">Tous créneaux</div>
              </div>

              <div className="bg-white p-4 rounded-xl border border-emerald-200 bg-emerald-50/20 shadow-xs">
                <div className="text-[11px] font-medium text-emerald-800 uppercase tracking-wider flex items-center gap-1">
                  <CheckCircle2 className="size-3 text-emerald-600" />
                  Confirmés
                </div>
                <div className="text-2xl font-bold text-emerald-700 mt-1">{stats.accepted}</div>
                <div className="text-[10px] text-emerald-600 mt-1">Validés en agence</div>
              </div>

              <div className="bg-white p-4 rounded-xl border border-amber-200 bg-amber-50/20 shadow-xs">
                <div className="text-[11px] font-medium text-amber-800 uppercase tracking-wider flex items-center gap-1">
                  <Clock className="size-3 text-amber-600" />
                  Proposés
                </div>
                <div className="text-2xl font-bold text-amber-700 mt-1">{stats.proposed}</div>
                <div className="text-[10px] text-amber-600 mt-1">En attente client</div>
              </div>

              <div className="bg-white p-4 rounded-xl border border-rose-200 bg-rose-50/20 shadow-xs">
                <div className="text-[11px] font-medium text-rose-800 uppercase tracking-wider flex items-center gap-1">
                  <XCircle className="size-3 text-rose-600" />
                  Rejetés
                </div>
                <div className="text-2xl font-bold text-rose-700 mt-1">{stats.rejected}</div>
                <div className="text-[10px] text-rose-600 mt-1">À replanifier</div>
              </div>

              <div className="bg-white p-4 rounded-xl border border-blue-200 bg-blue-50/20 shadow-xs">
                <div className="text-[11px] font-medium text-blue-800 uppercase tracking-wider flex items-center gap-1">
                  <Calendar className="size-3 text-blue-600" />
                  Aujourd'hui
                </div>
                <div className="text-2xl font-bold text-blue-700 mt-1">{stats.today}</div>
                <div className="text-[10px] text-blue-600 mt-1">Prévus ce jour</div>
              </div>

              <div className="bg-white p-4 rounded-xl border border-indigo-200 bg-indigo-50/20 shadow-xs">
                <div className="text-[11px] font-medium text-indigo-800 uppercase tracking-wider flex items-center gap-1">
                  <Navigation className="size-3 text-indigo-600" />
                  À venir
                </div>
                <div className="text-2xl font-bold text-indigo-700 mt-1">{stats.upcoming}</div>
                <div className="text-[10px] text-indigo-600 mt-1">Futures dates</div>
              </div>
            </div>

            {/* Filter & Search Bar */}
            <div className="bg-white p-4 rounded-xl border border-[#E0E4E9] shadow-xs flex flex-col md:flex-row gap-3 items-stretch md:items-center justify-between">
              {/* Search input */}
              <div className="relative flex-1">
                <Search className="size-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" />
                <input
                  type="text"
                  placeholder="Rechercher par nom client, CIN, n° de téléphone ou n° de demande..."
                  value={search}
                  onChange={(e) => {
                    setSearch(e.target.value);
                    setPage(1);
                  }}
                  className="w-full pl-9 pr-4 py-2 text-xs rounded-lg border border-[#E0E4E9] bg-[#FAFBFD] focus:outline-none focus:border-[#C0272D] focus:ring-1 focus:ring-[#C0272D]/20 placeholder:text-gray-400"
                />
              </div>

              {/* Filters */}
              <div className="flex flex-wrap items-center gap-2">
                {/* Status Selector */}
                <select
                  value={statusFilter}
                  onChange={(e) => {
                    setStatusFilter(e.target.value);
                    setPage(1);
                  }}
                  className="text-xs px-3 py-2 rounded-lg border border-[#E0E4E9] bg-white text-gray-700 focus:outline-none focus:border-[#C0272D]"
                >
                  <option value="all">Tous les Statuts</option>
                  <option value="accepted">Confirmé (Accepté)</option>
                  <option value="proposed">Proposé (En attente)</option>
                  <option value="rejected">Rejeté / Reporté</option>
                  <option value="cancelled">Annulé</option>
                </select>

                {/* Branch Selector */}
                <select
                  value={branchFilter}
                  onChange={(e) => {
                    setBranchFilter(e.target.value);
                    setPage(1);
                  }}
                  className="text-xs px-3 py-2 rounded-lg border border-[#E0E4E9] bg-white text-gray-700 focus:outline-none focus:border-[#C0272D] max-w-[200px] truncate"
                >
                  <option value="all">Toutes les Agences ({branches.length || 28})</option>
                  {branches.map((b) => (
                    <option key={b.id} value={b.id}>
                      {b.name} ({b.ville || 'Tunisie'})
                    </option>
                  ))}
                </select>

                {/* Date Selector */}
                <select
                  value={dateFilter}
                  onChange={(e) => {
                    setDateFilter(e.target.value);
                    setPage(1);
                  }}
                  className="text-xs px-3 py-2 rounded-lg border border-[#E0E4E9] bg-white text-gray-700 focus:outline-none focus:border-[#C0272D]"
                >
                  <option value="all">Toutes les Dates</option>
                  <option value="today">Aujourd'hui</option>
                  <option value="upcoming">À venir (Futurs)</option>
                  <option value="past">Passés (Historique)</option>
                </select>
              </div>
            </div>

            {/* Table of Appointments */}
            <div className="bg-white rounded-xl border border-[#E0E4E9] shadow-xs overflow-hidden">
              <div className="overflow-x-auto">
                <table className="w-full text-left text-xs">
                  <thead className="bg-[#FAFBFD] border-b border-[#E0E4E9] text-gray-600 font-semibold uppercase tracking-wider text-[11px]">
                    <tr>
                      <th className="px-4 py-3.5">Date & Heure RDV</th>
                      <th className="px-4 py-3.5">Client</th>
                      <th className="px-4 py-3.5">CIN & Contact</th>
                      <th className="px-4 py-3.5">Dossier de Crédit</th>
                      <th className="px-4 py-3.5">Agence BTS Affectée</th>
                      <th className="px-4 py-3.5 text-center">Statut RDV</th>
                      <th className="px-4 py-3.5 text-center">Tentative</th>
                      <th className="px-4 py-3.5 text-right">Actions</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-[#E0E4E9]">
                    {loading ? (
                      <tr>
                        <td colSpan={8} className="py-12 text-center text-gray-500">
                          <PageLoading />
                        </td>
                      </tr>
                    ) : appointments.length === 0 ? (
                      <tr>
                        <td colSpan={8} className="py-12 text-center text-gray-500 space-y-2">
                          <Calendar className="size-8 text-gray-300 mx-auto" />
                          <p className="font-semibold text-gray-700">Aucun rendez-vous trouvé</p>
                          <p className="text-[11px] text-gray-400">
                            Modifiez vos critères de recherche ou réinitialisez les filtres.
                          </p>
                        </td>
                      </tr>
                    ) : (
                      appointments.map((apt) => {
                        const isAccepted = apt.status === 'accepted';
                        const isProposed = apt.status === 'proposed';
                        const isRejected = apt.status === 'rejected';

                        return (
                          <tr key={apt.id} className="hover:bg-[#FAFBFD] transition-colors">
                            {/* Date & Time */}
                            <td className="px-4 py-3.5">
                              <div className="font-semibold text-[#0C1825] flex items-center gap-1.5">
                                <Calendar className="size-3.5 text-gray-400" />
                                <span>{formatFrenchDate(apt.scheduled_date)}</span>
                              </div>
                              <div className="text-[11px] text-[#C0272D] font-bold mt-0.5 flex items-center gap-1">
                                <Clock className="size-3" />
                                <span>{apt.time_formatted || apt.scheduled_time || 'Heure non précisée'}</span>
                              </div>
                            </td>

                            {/* Client */}
                            <td className="px-4 py-3.5">
                              <div className="font-bold text-[#0C1825] flex items-center gap-1.5">
                                <User className="size-3.5 text-gray-400" />
                                <span>{apt.client.name}</span>
                              </div>
                              {apt.client.profession && (
                                <div className="text-[11px] text-gray-500 truncate max-w-[160px]">
                                  {apt.client.profession}
                                </div>
                              )}
                            </td>

                            {/* CIN & Contact */}
                            <td className="px-4 py-3.5">
                              <div className="font-mono text-[11px] text-gray-800 font-medium">
                                CIN : {apt.client.cin || 'Non renseigné'}
                              </div>
                              <div className="text-[11px] text-gray-600 flex items-center gap-1 mt-0.5">
                                <Phone className="size-3 text-gray-400" />
                                <span>{apt.client.phone || 'Non renseigné'}</span>
                              </div>
                            </td>

                            {/* Application */}
                            <td className="px-4 py-3.5">
                              {apt.application ? (
                                <div>
                                  <Link
                                    href={`/applications/${apt.application.id}`}
                                    className="font-bold text-[#C0272D] hover:underline flex items-center gap-1"
                                  >
                                    <FileText className="size-3.5" />
                                    <span>{apt.application.application_number}</span>
                                  </Link>
                                  {apt.application.amount && (
                                    <div className="text-[11px] text-gray-500 font-medium">
                                      {Number(apt.application.amount).toLocaleString('fr-TN')} TND
                                    </div>
                                  )}
                                </div>
                              ) : (
                                <span className="text-gray-400 italic">Dossier archivé</span>
                              )}
                            </td>

                            {/* Branch */}
                            <td className="px-4 py-3.5">
                              {apt.branch ? (
                                <div>
                                  <div className="font-semibold text-[#0C1825] flex items-center gap-1">
                                    <Building2 className="size-3.5 text-gray-400 shrink-0" />
                                    <span className="truncate max-w-[180px]">{apt.branch.name}</span>
                                  </div>
                                  <div className="text-[11px] text-gray-500 truncate max-w-[200px] mt-0.5">
                                    {apt.branch.address}
                                  </div>
                                  {apt.branch.google_maps_url && (
                                    <a
                                      href={apt.branch.google_maps_url}
                                      target="_blank"
                                      rel="noreferrer"
                                      className="text-[10px] text-blue-600 hover:underline inline-flex items-center gap-0.5 mt-0.5"
                                    >
                                      <span>Google Maps</span>
                                      <ExternalLink className="size-2.5" />
                                    </a>
                                  )}
                                </div>
                              ) : (
                                <span className="text-gray-400">Non assignée</span>
                              )}
                            </td>

                            {/* Status */}
                            <td className="px-4 py-3.5 text-center">
                              {isAccepted && (
                                <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                  <CheckCircle2 className="size-3 text-emerald-600" />
                                  Confirmé
                                </span>
                              )}
                              {isProposed && (
                                <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                  <Clock className="size-3 text-amber-600" />
                                  Proposé
                                </span>
                              )}
                              {isRejected && (
                                <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-bold bg-rose-50 text-rose-700 border border-rose-200">
                                  <XCircle className="size-3 text-rose-600" />
                                  Rejeté
                                </span>
                              )}
                              {!isAccepted && !isProposed && !isRejected && (
                                <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-medium bg-gray-100 text-gray-700">
                                  {apt.status}
                                </span>
                              )}
                            </td>

                            {/* Attempt */}
                            <td className="px-4 py-3.5 text-center">
                              <span className="inline-block px-2 py-0.5 rounded bg-gray-100 font-mono text-[11px] font-semibold text-gray-700">
                                {apt.attempt_number} / {apt.max_attempts}
                              </span>
                            </td>

                            {/* Actions */}
                            <td className="px-4 py-3.5 text-right space-x-1">
                              {apt.application && (
                                <div className="inline-flex items-center gap-1.5">
                                  <Link
                                    href={`/reports/${apt.application.id}`}
                                    className="p-1.5 rounded-lg border border-[#E0E4E9] bg-white hover:border-[#C0272D] hover:text-[#C0272D] text-gray-600 transition-colors inline-flex items-center justify-center"
                                    title="Ouvrir la discussion / dialog zone"
                                  >
                                    <MessageSquare className="size-3.5" />
                                  </Link>
                                  <Link
                                    href={`/applications/${apt.application.id}`}
                                    className="p-1.5 rounded-lg bg-[#C0272D] text-white hover:bg-[#A52025] transition-colors inline-flex items-center justify-center"
                                    title="Voir la demande complète"
                                  >
                                    <ChevronRight className="size-3.5" />
                                  </Link>
                                </div>
                              )}
                            </td>
                          </tr>
                        );
                      })
                    )}
                  </tbody>
                </table>
              </div>

              {/* Pagination footer */}
              {totalPages > 1 && (
                <div className="p-4 bg-[#FAFBFD] border-t border-[#E0E4E9] flex items-center justify-between text-xs text-gray-600">
                  <div>
                    Affichage page <span className="font-bold">{page}</span> sur{' '}
                    <span className="font-bold">{totalPages}</span> ({totalAppointments} rendez-vous au total)
                  </div>
                  <div className="flex items-center gap-1">
                    <button
                      type="button"
                      disabled={page <= 1}
                      onClick={() => setPage((p) => Math.max(p - 1, 1))}
                      className="px-3 py-1.5 rounded-lg border border-[#E0E4E9] bg-white disabled:opacity-40 disabled:cursor-not-allowed hover:bg-gray-50"
                    >
                      Précédent
                    </button>
                    <button
                      type="button"
                      disabled={page >= totalPages}
                      onClick={() => setPage((p) => Math.min(p + 1, totalPages))}
                      className="px-3 py-1.5 rounded-lg border border-[#E0E4E9] bg-white disabled:opacity-40 disabled:cursor-not-allowed hover:bg-gray-50"
                    >
                      Suivant
                    </button>
                  </div>
                </div>
              )}
            </div>
          </div>
        )}

        {/* TAB 2: ALL BTS BANK BRANCHES VIEW */}
        {activeTab === 'branches' && (
          <div className="space-y-6 animate-in fade-in duration-200">
            {/* Search and Network Info */}
            <div className="bg-white p-5 rounded-xl border border-[#E0E4E9] shadow-xs flex flex-col md:flex-row gap-4 items-stretch md:items-center justify-between">
              <div>
                <h2 className="text-base font-bold text-[#0C1825] flex items-center gap-2">
                  <Building2 className="size-4 text-[#C0272D]" />
                  <span>Réseau National BTS Bank (28 Agences & Succursales)</span>
                </h2>
                <p className="text-xs text-gray-500 mt-0.5">
                  Toutes les agences régionales, coordonnées téléphoniques, fax officiels et localisation GPS.
                </p>
              </div>

              <div className="relative w-full md:w-80">
                <Search className="size-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" />
                <input
                  type="text"
                  placeholder="Filtrer par nom d'agence, ville ou adresse..."
                  value={branchSearch}
                  onChange={(e) => setBranchSearch(e.target.value)}
                  className="w-full pl-9 pr-4 py-2 text-xs rounded-lg border border-[#E0E4E9] bg-[#FAFBFD] focus:outline-none focus:border-[#C0272D] focus:ring-1 focus:ring-[#C0272D]/20 placeholder:text-gray-400"
                />
              </div>
            </div>

            {/* Table of Branches */}
            <div className="bg-white rounded-xl border border-[#E0E4E9] shadow-xs overflow-hidden">
              <div className="overflow-x-auto">
                <table className="w-full text-left text-xs">
                  <thead className="bg-[#FAFBFD] border-b border-[#E0E4E9] text-gray-600 font-semibold uppercase tracking-wider text-[11px]">
                    <tr>
                      <th className="px-4 py-3.5">Agence BTS</th>
                      <th className="px-4 py-3.5">Adresse Complète</th>
                      <th className="px-4 py-3.5">Téléphone(s)</th>
                      <th className="px-4 py-3.5">Fax</th>
                      <th className="px-4 py-3.5">Créneaux & Horaires</th>
                      <th className="px-4 py-3.5 text-center">Localisation GPS</th>
                      <th className="px-4 py-3.5 text-center">Rendez-vous</th>
                      <th className="px-4 py-3.5 text-right">Action</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-[#E0E4E9]">
                    {filteredBranches.length === 0 ? (
                      <tr>
                        <td colSpan={8} className="py-12 text-center text-gray-500">
                          Aucune agence correspondant aux critères.
                        </td>
                      </tr>
                    ) : (
                      filteredBranches.map((branch) => (
                        <tr key={branch.id} className="hover:bg-[#FAFBFD] transition-colors">
                          {/* Branch Name & Badge */}
                          <td className="px-4 py-3.5">
                            <div className="font-bold text-[#0C1825] flex items-center gap-1.5">
                              <Building2 className="size-3.5 text-[#C0272D]" />
                              <span>{branch.name}</span>
                            </div>
                            {branch.is_default && (
                              <span className="inline-block mt-1 px-2 py-0.5 text-[10px] font-bold rounded bg-red-100 text-[#C0272D]">
                                Succursale Centrale
                              </span>
                            )}
                            {branch.ville && !branch.is_default && (
                              <div className="text-[11px] text-gray-500">{branch.ville}</div>
                            )}
                          </td>

                          {/* Address */}
                          <td className="px-4 py-3.5 text-gray-700">
                            <div className="flex items-start gap-1 max-w-[240px]">
                              <MapPin className="size-3 text-gray-400 mt-0.5 shrink-0" />
                              <span className="text-[11px] leading-relaxed">{branch.address}</span>
                            </div>
                          </td>

                          {/* Phone */}
                          <td className="px-4 py-3.5">
                            <div className="flex items-center gap-1 text-[#0C1825] font-mono text-[11px] font-medium">
                              <Phone className="size-3 text-gray-400" />
                              <span>{branch.phone || 'Non renseigné'}</span>
                            </div>
                          </td>

                          {/* Fax */}
                          <td className="px-4 py-3.5">
                            <div className="flex items-center gap-1 text-gray-600 font-mono text-[11px]">
                              <Printer className="size-3 text-gray-400" />
                              <span>{branch.fax || '—'}</span>
                            </div>
                          </td>

                          {/* Slots & Hours */}
                          <td className="px-4 py-3.5">
                            <div className="text-[11px] text-gray-700 font-medium">{branch.opening_hours}</div>
                            <div className="text-[10px] text-gray-500 mt-0.5">
                              {branch.daily_capacity} créneaux / jour ({branch.slot_times?.map((t) => t.slice(0, 5)).join(', ')})
                            </div>
                          </td>

                          {/* GPS / Google Maps */}
                          <td className="px-4 py-3.5 text-center">
                            {branch.google_maps_url ? (
                              <a
                                href={branch.google_maps_url}
                                target="_blank"
                                rel="noreferrer"
                                className="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg border border-blue-200 bg-blue-50 text-blue-700 hover:bg-blue-100 font-medium text-[11px] transition-colors"
                              >
                                <Globe className="size-3 text-blue-600" />
                                <span>Ouvrir Maps</span>
                              </a>
                            ) : (
                              <span className="text-gray-400 text-[11px]">Non disponible</span>
                            )}
                          </td>

                          {/* Appointments metrics */}
                          <td className="px-4 py-3.5 text-center">
                            <div className="font-bold text-[#0C1825] text-xs">
                              {branch.appointments_count} RDV
                            </div>
                            <div className="text-[10px] text-emerald-700">
                              {branch.accepted_appointments_count} confirmés
                            </div>
                          </td>

                          {/* Action */}
                          <td className="px-4 py-3.5 text-right">
                            <button
                              type="button"
                              onClick={() => handleFilterBranchClick(branch.id)}
                              className="px-2.5 py-1 text-[11px] font-semibold rounded-lg bg-[#FAFBFD] border border-[#E0E4E9] text-[#C0272D] hover:bg-red-50 hover:border-red-200 transition-colors"
                            >
                              Filtrer RDV
                            </button>
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        )}
      </main>
    </div>
  );
}
