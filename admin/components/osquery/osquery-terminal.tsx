'use client';

import { useState, useEffect, useCallback } from 'react';
import {
  Terminal,
  Play,
  RefreshCw,
  Database,
  Search,
  CheckCircle2,
  Clock,
  Zap,
  Sparkles,
  Layers,
  ChevronDown,
  History,
  AlertCircle,
  Copy,
  Check,
  FileSpreadsheet,
  FileCode,
} from 'lucide-react';
import {
  runOsqueryQuery,
  getOsqueryStatus,
  type OsqueryQueryResultDto,
  type OsqueryStatusDto,
  type OsqueryPresetCategoryDto,
} from '@/lib/api/osquery';
import { OsquerySchemaModal } from './osquery-schema-modal';
import { cn } from '@/lib/utils';
import { escapeCsvCell, saveBlob } from '@/lib/download';

interface OsqueryTerminalProps {
  initialQuery?: string;
  presets?: OsqueryPresetCategoryDto[];
}

const DEFAULT_QUERY = 'SELECT pid, port, protocol, address, process_name, state FROM listening_ports WHERE port != 0 ORDER BY port ASC;';

export function OsqueryTerminal({ initialQuery, presets = [] }: OsqueryTerminalProps) {
  const [sql, setSql] = useState(initialQuery || DEFAULT_QUERY);
  const [loading, setLoading] = useState(false);
  const [status, setStatus] = useState<OsqueryStatusDto | null>(null);
  const [result, setResult] = useState<OsqueryQueryResultDto | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [history, setHistory] = useState<string[]>(() => {
    if (typeof window === 'undefined') return [];
    try {
      const saved = localStorage.getItem('bts_osquery_history');
      return saved ? JSON.parse(saved) : [];
    } catch {
      return [];
    }
  });
  const [showHistory, setShowHistory] = useState(false);
  const [showSchema, setShowSchema] = useState(false);
  const [tableSearch, setTableSearch] = useState('');
  const [sortCol, setSortCol] = useState<string | null>(null);
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('asc');
  const [copied, setCopied] = useState(false);
  const [page, setPage] = useState(1);
  const perPage = 15;

  // Fetch status on mount
  useEffect(() => {
    getOsqueryStatus()
      .then(setStatus)
      .catch((statusFailure) => {
        setError(statusFailure instanceof Error ? statusFailure.message : 'Impossible de charger l’état du moteur Osquery.');
      });
  }, []);

  // Execute query handler
  const handleExecute = useCallback(
    async (queryToRun?: string) => {
      const q = (queryToRun || sql).trim();
      if (!q) return;

      setLoading(true);
      setError(null);

      try {
        const res = await runOsqueryQuery(q);
        setResult(res);
        setPage(1);

        // Update history
        setHistory((prev) => {
          const next = [q, ...prev.filter((item) => item !== q)].slice(0, 20);
          try {
            localStorage.setItem('bts_osquery_history', JSON.stringify(next));
          } catch {}
          return next;
        });
      } catch (err: unknown) {
        if (err instanceof Error) {
          setError(err.message);
        } else if (typeof err === 'object' && err !== null && 'message' in err) {
          setError(String((err as { message: string }).message));
        } else {
          setError('Une erreur est survenue lors de l\'exécution de la requête Osquery.');
        }
      } finally {
        setLoading(false);
      }
    },
    [sql]
  );

  // Run initial query if passed or on first mount
  useEffect(() => {
    queueMicrotask(() => void handleExecute(initialQuery || DEFAULT_QUERY));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Handle keyboard shortcut Ctrl+Enter / Cmd+Enter
  function handleKeyDown(e: React.KeyboardEvent<HTMLTextAreaElement>) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
      e.preventDefault();
      handleExecute();
    }
  }

  function handleSelectPreset(presetSql: string) {
    setSql(presetSql);
    handleExecute(presetSql);
  }

  function handleCopySQL() {
    navigator.clipboard.writeText(sql);
    setCopied(true);
    setTimeout(() => setCopied(false), 1500);
  }

  function exportCSV() {
    if (!result || result.rows.length === 0) return;
    const header = result.columns.map(escapeCsvCell).join(',') + '\n';
    const rows = result.rows
      .map((row) =>
        result.columns
          .map((col) => {
            return escapeCsvCell(row[col]);
          })
          .join(',')
      )
      .join('\n');

    const blob = new Blob([header + rows], { type: 'text/csv;charset=utf-8;' });
    saveBlob(blob, `osquery_export_${new Date().toISOString().slice(0, 10)}.csv`);
  }

  function exportJSON() {
    if (!result) return;
    const blob = new Blob([JSON.stringify(result.rows, null, 2)], { type: 'application/json' });
    saveBlob(blob, `osquery_export_${new Date().toISOString().slice(0, 10)}.json`);
  }

  // Filter and sort result rows
  const filteredRows = (result?.rows || []).filter((row) => {
    if (!tableSearch.trim()) return true;
    const q = tableSearch.toLowerCase();
    return Object.values(row).some((val) => String(val).toLowerCase().includes(q));
  });

  const sortedRows = [...filteredRows].sort((a, b) => {
    if (!sortCol) return 0;
    const valA = a[sortCol] ?? '';
    const valB = b[b[sortCol] !== undefined ? sortCol : ''] ?? '';

    if (typeof valA === 'number' && typeof valB === 'number') {
      return sortDir === 'asc' ? valA - valB : valB - valA;
    }
    const cmp = String(valA).localeCompare(String(valB));
    return sortDir === 'asc' ? cmp : -cmp;
  });

  const totalPages = Math.ceil(sortedRows.length / perPage) || 1;
  const paginatedRows = sortedRows.slice((page - 1) * perPage, page * perPage);

  return (
    <div className="space-y-4">
      {/* ── Status Banner ── */}
      <div className="bg-white rounded-xl border border-[#E0E4E9] p-4 shadow-xs flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <div className="size-9 rounded-xl bg-[#FDF2F2] text-[#C0272D] flex items-center justify-center shrink-0 border border-[#FECACA]">
            <Terminal className="size-5" />
          </div>
          <div>
            <div className="flex items-center gap-2">
              <h3 className="text-sm font-bold text-[#0C1825]">Moteur Osquery BTS Bank</h3>
              <span
                className={cn(
                  'inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold border',
                  status?.engine_mode === 'native'
                    ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
                    : 'bg-indigo-50 text-indigo-700 border-indigo-200'
                )}
              >
                <Zap className="size-2.5" />
                {status?.engine_mode === 'native' ? 'Binaire Natif Osquery Actif' : 'Moteur Télémétrie Système Intégré'}
              </span>
            </div>
            <p className="text-xs text-[#3D5166] mt-0.5">
              {status?.version || 'Osquery v5.14.0 Core'} · {status?.tables_count || 12} tables système interrogeables en SQL standard
            </p>
          </div>
        </div>

        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={() => setShowSchema(true)}
            className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-[#3D5166] bg-[#F4F6F8] hover:bg-[#E0E4E9] rounded-lg transition-all border border-[#E0E4E9]"
          >
            <Database className="size-3.5 text-[#C0272D]" />
            <span>Explorer les Tables ({status?.tables_count || 12})</span>
          </button>
        </div>
      </div>

      {/* ── SQL Editor Box ── */}
      <div className="bg-white rounded-xl border border-[#E0E4E9] shadow-xs overflow-hidden">
        {/* Editor Toolbar */}
        <div className="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5 bg-[#FAFBFD] border-b border-[#E0E4E9] text-xs">
          <div className="flex items-center gap-2">
            <span className="font-bold text-[#0C1825] flex items-center gap-1.5">
              <Layers className="size-3.5 text-[#C0272D]" />
              Console SQL Osquery
            </span>

            {/* Presets dropdown */}
            {presets.length > 0 && (
              <div className="relative group">
                <button
                  type="button"
                  className="inline-flex items-center gap-1 px-2.5 py-1 rounded-md bg-white border border-[#E0E4E9] text-gray-700 hover:text-[#C0272D] text-[11px] font-semibold"
                >
                  <Sparkles className="size-3 text-[#C0272D]" />
                  <span>Requêtes Prédéfinies</span>
                  <ChevronDown className="size-3 text-gray-400" />
                </button>
                <div className="absolute left-0 top-full mt-1 w-72 bg-white rounded-xl border border-[#E0E4E9] shadow-lg p-2 hidden group-hover:block z-30 space-y-2 max-h-80 overflow-y-auto">
                  {presets.map((cat) => (
                    <div key={cat.category} className="space-y-1">
                      <p className="text-[10px] font-bold uppercase tracking-wider text-gray-400 px-2 pt-1">
                        {cat.title}
                      </p>
                      {cat.queries.map((q) => (
                        <button
                          key={q.name}
                          type="button"
                          onClick={() => handleSelectPreset(q.sql)}
                          className="w-full text-left px-2 py-1.5 rounded-lg text-xs hover:bg-[#FDF2F2] hover:text-[#C0272D] transition-colors"
                        >
                          <p className="font-semibold">{q.name}</p>
                          <p className="text-[10px] text-gray-500 font-mono truncate">{q.sql}</p>
                        </button>
                      ))}
                    </div>
                  ))}
                </div>
              </div>
            )}
          </div>

          <div className="flex items-center gap-2">
            {/* History Toggle */}
            <button
              type="button"
              onClick={() => setShowHistory(!showHistory)}
              className={cn(
                'inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-[11px] font-semibold border transition-all',
                showHistory ? 'bg-[#FDF2F2] text-[#C0272D] border-[#FECACA]' : 'bg-white text-gray-700 border-[#E0E4E9] hover:bg-gray-50'
              )}
            >
              <History className="size-3" />
              <span>Historique ({history.length})</span>
            </button>

            {/* Copy SQL */}
            <button
              type="button"
              onClick={handleCopySQL}
              className="p-1.5 rounded-md bg-white border border-[#E0E4E9] text-gray-600 hover:text-[#0C1825]"
              title="Copier la requête"
            >
              {copied ? <Check className="size-3.5 text-emerald-600" /> : <Copy className="size-3.5" />}
            </button>

            {/* Clear */}
            <button
              type="button"
              onClick={() => setSql('')}
              className="px-2 py-1 rounded-md bg-white border border-[#E0E4E9] text-gray-600 hover:text-red-600 text-[11px]"
            >
              Effacer
            </button>
          </div>
        </div>

        {/* History Drawer */}
        {showHistory && history.length > 0 && (
          <div className="bg-[#FAFBFD] border-b border-[#E0E4E9] p-3 max-h-40 overflow-y-auto space-y-1.5">
            <p className="text-[10px] font-bold uppercase tracking-wider text-gray-500">Dernières requêtes exécutées :</p>
            <div className="space-y-1">
              {history.map((h, i) => (
                <button
                  key={i}
                  type="button"
                  onClick={() => {
                    setSql(h);
                    handleExecute(h);
                  }}
                  className="w-full text-left font-mono text-xs px-2.5 py-1 rounded bg-white border border-[#E0E4E9] text-gray-700 hover:bg-[#FDF2F2] hover:text-[#C0272D] hover:border-[#FECACA] truncate block transition-all"
                >
                  {h}
                </button>
              ))}
            </div>
          </div>
        )}

        {/* Textarea Input */}
        <div className="relative">
          <textarea
            value={sql}
            onChange={(e) => setSql(e.target.value)}
            onKeyDown={handleKeyDown}
            rows={3}
            placeholder="Saisissez votre requête SQL Osquery (ex: SELECT * FROM listening_ports;)"
            className="w-full p-4 font-mono text-xs sm:text-sm text-[#0C1825] bg-[#FFFFFF] border-none focus:outline-hidden focus:ring-0 resize-y"
          />
        </div>

        {/* Action Bar */}
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 px-4 py-3 bg-[#FAFBFD] border-t border-[#E0E4E9]">
          <span className="text-[11px] text-[#3D5166] flex items-center gap-1">
            <kbd className="px-1.5 py-0.5 bg-white rounded border border-[#E0E4E9] font-mono text-[10px] shadow-2xs">Ctrl</kbd>
            +
            <kbd className="px-1.5 py-0.5 bg-white rounded border border-[#E0E4E9] font-mono text-[10px] shadow-2xs">Entrée</kbd>
            <span>pour exécuter la requête</span>
          </span>

          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={() => handleExecute()}
              disabled={loading || !sql.trim()}
              className="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-bold text-white bg-[#C0272D] hover:bg-[#A01E23] rounded-xl shadow-xs transition-all disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {loading ? (
                <RefreshCw className="size-3.5 animate-spin" />
              ) : (
                <Play className="size-3.5 fill-current" />
              )}
              <span>{loading ? 'Exécution en cours...' : 'Exécuter la Requête'}</span>
            </button>
          </div>
        </div>
      </div>

      {/* ── Error Box ── */}
      {error && (
        <div className="p-4 rounded-xl bg-red-50 border border-red-200 text-red-700 flex items-start gap-3 text-xs animate-in fade-in duration-200">
          <AlertCircle className="size-4 shrink-0 mt-0.5 text-red-600" />
          <div className="space-y-1">
            <p className="font-bold">Erreur d’exécution Osquery</p>
            <p className="text-red-600">{error}</p>
          </div>
        </div>
      )}

      {/* ── Results Container ── */}
      {result && (
        <div className="bg-white rounded-xl border border-[#E0E4E9] shadow-xs overflow-hidden space-y-0">
          {/* Results Header */}
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-4 bg-[#FAFBFD] border-b border-[#E0E4E9]">
            <div className="flex items-center gap-3 flex-wrap">
              <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-50 text-emerald-800 border border-emerald-200">
                <CheckCircle2 className="size-3" />
                {result.count} {result.count > 1 ? 'résultats' : 'résultat'}
              </span>

              <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700">
                <Clock className="size-3 text-gray-500" />
                {result.execution_time_ms} ms
              </span>

              <span className="text-xs text-gray-500 font-mono hidden md:inline">
                {result.columns.length} colonnes projetées
              </span>
            </div>

            <div className="flex items-center gap-2">
              {/* Filter search */}
              <div className="relative">
                <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 size-3.5 text-gray-400" />
                <input
                  type="text"
                  placeholder="Filtrer les résultats..."
                  value={tableSearch}
                  onChange={(e) => setTableSearch(e.target.value)}
                  className="pl-8 pr-3 py-1.5 text-xs bg-white rounded-lg border border-[#E0E4E9] placeholder:text-gray-400 focus:outline-hidden focus:border-[#C0272D]"
                />
              </div>

              {/* Export CSV */}
              <button
                type="button"
                onClick={exportCSV}
                className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-white border border-[#E0E4E9] text-xs font-semibold text-gray-700 hover:text-[#C0272D] hover:border-[#C0272D]/30 transition-all shadow-2xs"
                title="Exporter en CSV"
              >
                <FileSpreadsheet className="size-3.5 text-emerald-600" />
                <span className="hidden sm:inline">CSV</span>
              </button>

              {/* Export JSON */}
              <button
                type="button"
                onClick={exportJSON}
                className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-white border border-[#E0E4E9] text-xs font-semibold text-gray-700 hover:text-[#C0272D] hover:border-[#C0272D]/30 transition-all shadow-2xs"
                title="Exporter en JSON"
              >
                <FileCode className="size-3.5 text-indigo-600" />
                <span className="hidden sm:inline">JSON</span>
              </button>
            </div>
          </div>

          {/* Table */}
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs font-mono">
              <thead className="bg-[#FAFBFD] border-b border-[#E0E4E9] text-gray-600 font-bold uppercase tracking-wider text-[10px]">
                <tr>
                  {result.columns.map((col) => (
                    <th
                      key={col}
                      onClick={() => {
                        if (sortCol === col) {
                          setSortDir(sortDir === 'asc' ? 'desc' : 'asc');
                        } else {
                          setSortCol(col);
                          setSortDir('asc');
                        }
                      }}
                      className="px-4 py-3 cursor-pointer hover:bg-gray-100 transition-colors whitespace-nowrap select-none"
                    >
                      <div className="flex items-center gap-1.5">
                        <span>{col}</span>
                        {sortCol === col && (
                          <span className="text-[#C0272D] font-bold">{sortDir === 'asc' ? '▲' : '▼'}</span>
                        )}
                      </div>
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-[#E0E4E9]">
                {paginatedRows.length === 0 ? (
                  <tr>
                    <td colSpan={result.columns.length} className="py-8 text-center text-gray-400 font-sans">
                      Aucune ligne ne correspond au filtre de recherche.
                    </td>
                  </tr>
                ) : (
                  paginatedRows.map((row, rIdx) => (
                    <tr key={rIdx} className="hover:bg-[#FAFBFD] transition-colors">
                      {result.columns.map((col) => {
                        const val = row[col];
                        return (
                          <td key={col} className="px-4 py-2.5 text-gray-800 whitespace-nowrap">
                            {renderCellContent(col, val)}
                          </td>
                        );
                      })}
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>

          {/* Pagination Footer */}
          {sortedRows.length > perPage && (
            <div className="p-3 bg-[#FAFBFD] border-t border-[#E0E4E9] flex items-center justify-between text-xs text-gray-600 font-sans">
              <div>
                Affichage de <span className="font-bold">{(page - 1) * perPage + 1}</span> à{' '}
                <span className="font-bold">{Math.min(page * perPage, sortedRows.length)}</span> sur{' '}
                <span className="font-bold">{sortedRows.length}</span> lignes
              </div>
              <div className="flex items-center gap-1">
                <button
                  type="button"
                  disabled={page <= 1}
                  onClick={() => setPage((p) => Math.max(p - 1, 1))}
                  className="px-2.5 py-1 rounded-md border border-[#E0E4E9] bg-white disabled:opacity-40 font-medium hover:bg-gray-50"
                >
                  Précédent
                </button>
                <span className="px-2 font-bold">
                  {page} / {totalPages}
                </span>
                <button
                  type="button"
                  disabled={page >= totalPages}
                  onClick={() => setPage((p) => Math.min(p + 1, totalPages))}
                  className="px-2.5 py-1 rounded-md border border-[#E0E4E9] bg-white disabled:opacity-40 font-medium hover:bg-gray-50"
                >
                  Suivant
                </button>
              </div>
            </div>
          )}
        </div>
      )}

      {/* Schema Modal */}
      <OsquerySchemaModal
        status={status}
        isOpen={showSchema}
        onClose={() => setShowSchema(false)}
        onSelectQuery={(query) => {
          setSql(query);
          handleExecute(query);
        }}
      />
    </div>
  );
}

function renderCellContent(col: string, val: unknown) {
  if (val === null || val === undefined || val === '') {
    return <span className="text-gray-300">—</span>;
  }

  // Boolean or binary state
  if (val === 1 || val === true) {
    if (col.includes('is_') || col === 'is_active' || col === 'is_primary') {
      return (
        <span className="px-1.5 py-0.5 rounded text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
          OUI (1)
        </span>
      );
    }
  }
  if (val === 0 || val === false) {
    if (col.includes('is_') || col === 'is_active' || col === 'is_primary') {
      return (
        <span className="px-1.5 py-0.5 rounded text-[10px] font-medium bg-gray-100 text-gray-500">
          NON (0)
        </span>
      );
    }
  }

  // MAC addresses
  if (col.includes('mac') && typeof val === 'string' && val.includes(':')) {
    return (
      <span className="font-bold text-indigo-700 bg-indigo-50/70 px-1.5 py-0.5 rounded border border-indigo-100">
        {val}
      </span>
    );
  }

  // Ports
  if (col === 'port' && typeof val === 'number') {
    return (
      <span className="font-bold text-[#C0272D] bg-[#FDF2F2] px-1.5 py-0.5 rounded border border-[#FECACA]">
        :{val}
      </span>
    );
  }

  // Status
  if (col === 'status' || col === 'state') {
    const s = String(val).toLowerCase();
    if (s.includes('up') || s.includes('listening') || s.includes('active') || s.includes('healthy')) {
      return (
        <span className="px-1.5 py-0.5 rounded text-[10px] font-bold bg-emerald-50 text-emerald-800 border border-emerald-200">
          {String(val)}
        </span>
      );
    }
    if (s.includes('down') || s.includes('deconnect') || s.includes('warning') || s.includes('critique')) {
      return (
        <span className="px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-50 text-amber-800 border border-amber-200">
          {String(val)}
        </span>
      );
    }
  }

  return String(val);
}
