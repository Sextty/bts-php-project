'use client';

import { useState, useEffect } from 'react';
import {
  Terminal,
  Play,
  Database,
  Download,
  AlertTriangle,
  CheckCircle2,
  Search,
} from 'lucide-react';
import {
  getSecurityOsqueryStatus,
  runSecurityOsqueryQuery,
  getSecurityOsqueryPresets,
  OsqueryStatusData,
  OsqueryQueryResult,
  OsqueryPresetCategory,
} from '@/lib/api/osquery';
import { getErrorMessage } from '@/lib/api/client';
import { escapeCsvCell, saveBlob } from '@/lib/download';
import { AccessibleModal } from '@/components/accessible-modal';

export default function SecurityOsqueryPage() {
  const [status, setStatus] = useState<OsqueryStatusData | null>(null);
  const [presets, setPresets] = useState<OsqueryPresetCategory[]>([]);
  const [sql, setSql] = useState('SELECT hostname, cpu_brand, physical_memory, hardware_vendor FROM system_info;');
  const [result, setResult] = useState<OsqueryQueryResult | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [history, setHistory] = useState<string[]>(() => {
    if (typeof window === 'undefined') return [];
    try {
      const saved = localStorage.getItem('bts_sc_osquery_history');
      const parsed: unknown = saved ? JSON.parse(saved) : [];
      return Array.isArray(parsed) && parsed.every((item) => typeof item === 'string') ? parsed.slice(0, 15) : [];
    } catch {
      return [];
    }
  });
  const [showSchemaModal, setShowSchemaModal] = useState(false);
  const [schemaSearch, setSchemaSearch] = useState('');

  useEffect(() => {
    const loadInit = async () => {
      const [statusResult, presetResult] = await Promise.allSettled([
          getSecurityOsqueryStatus(),
          getSecurityOsqueryPresets(),
      ]);
      if (statusResult.status === 'fulfilled') setStatus(statusResult.value);
      if (presetResult.status === 'fulfilled') setPresets(presetResult.value);
      const failure = [statusResult, presetResult].find((item) => item.status === 'rejected');
      if (failure?.status === 'rejected') setError(getErrorMessage(failure.reason, 'Certaines données Osquery sont indisponibles.'));
    };
    void loadInit();
  }, []);

  const handleExecute = async () => {
    if (!sql.trim()) return;
    setLoading(true);
    setError(null);
    try {
      const res = await runSecurityOsqueryQuery(sql);
      setResult(res);

      const nextHistory = [sql, ...history.filter((h) => h !== sql)].slice(0, 15);
      setHistory(nextHistory);
      try {
        localStorage.setItem('bts_sc_osquery_history', JSON.stringify(nextHistory));
      } catch {
        // Query remains successful when browser storage is unavailable.
      }
    } catch (queryError: unknown) {
      setError(getErrorMessage(queryError, 'Erreur lors de l’exécution SQL'));
      setResult(null);
    } finally {
      setLoading(false);
    }
  };

  const handleKeyDown = (e: React.KeyboardEvent<HTMLTextAreaElement>) => {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
      e.preventDefault();
      handleExecute();
    }
  };

  const exportResult = (format: 'csv' | 'json') => {
    if (!result || result.rows.length === 0) return;

    if (format === 'json') {
      const blob = new Blob([JSON.stringify(result.rows, null, 2)], { type: 'application/json' });
      saveBlob(blob, `osquery-result-${Date.now()}.json`);
    } else {
      const headers = result.columns.map(escapeCsvCell).join(',');
      const rows = result.rows.map((r) =>
        result.columns.map((col) => escapeCsvCell(r[col])).join(',')
      );
      const csv = [headers, ...rows].join('\n');
      const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
      saveBlob(blob, `osquery-result-${Date.now()}.csv`);
    }
  };

  return (
    <div className="sc-page">
      {/* ── Header ── */}
      <div className="sc-page-header flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <div className="flex items-center gap-2.5">
            <h1 className="text-xl font-bold text-[#0C1825] tracking-tight">Console Osquery SQL</h1>
            <span className="px-2.5 py-0.5 text-xs font-semibold bg-[#FDF2F2] text-[#C0272D] border border-[#F5C2C4] rounded-full">
              {status ? `${status.tables_count} tables · ${status.engine_mode === 'native' ? 'natif' : 'télémétrie'}` : 'Chargement…'}
            </span>
          </div>
          <p className="text-xs text-[#3D5166] mt-1">
            Interrogation directe des tables système et de la base d’audit BTS Bank via le langage SQL standard
          </p>
        </div>

        <div className="flex items-center gap-2.5">
          <button
            onClick={() => setShowSchemaModal(true)}
            className="flex items-center gap-2 px-3.5 py-2 rounded-xl bg-white border border-[#E0E4E9] hover:bg-[#F4F6F8] text-xs font-medium text-[#3D5166] transition-colors shadow-2xs"
          >
            <Database className="size-3.5 text-[#C0272D]" />
            <span>Catalogue des Schémas</span>
          </button>
        </div>
      </div>

      {/* ── Editor & Presets Card ── */}
      <div className="bg-white border border-[#E0E4E9] rounded-2xl p-6 shadow-xs space-y-4">
        <div className="flex flex-wrap items-center justify-between gap-3 pb-3 border-b border-[#E0E4E9]">
          <div className="flex items-center gap-2 text-xs font-bold text-[#0C1825]">
            <Terminal className="size-4 text-[#C0272D]" />
            <span>Éditeur de Requêtes SQL</span>
            <span className="text-[11px] font-normal text-[#3D5166]">(Ctrl + Entrée pour exécuter)</span>
          </div>

          <div className="flex items-center gap-2">
            <span className="text-xs text-[#3D5166] font-medium">Modèles prédéfinis :</span>
            <select
              onChange={(e) => {
                if (e.target.value) setSql(e.target.value);
              }}
              defaultValue=""
              className="px-3 py-1.5 bg-white border border-[#E0E4E9] rounded-xl text-xs text-[#0C1825] focus:outline-none focus:border-[#C0272D]"
            >
              <option value="" disabled>
                Choisir une requête type...
              </option>
              {presets.map((cat) => (
                <optgroup key={cat.category} label={cat.title}>
                  {cat.queries.map((q) => (
                    <option key={q.name} value={q.sql}>
                      {q.name}
                    </option>
                  ))}
                </optgroup>
              ))}
            </select>
          </div>
        </div>

        <div>
          <textarea
            rows={4}
            value={sql}
            onChange={(e) => setSql(e.target.value)}
            onKeyDown={handleKeyDown}
            placeholder="SELECT * FROM system_info;"
            className="w-full p-4 bg-[#F8FAFC] border border-[#E0E4E9] rounded-xl text-xs font-mono text-[#0C1825] placeholder:text-[#8C9BAE] focus:outline-none focus:border-[#C0272D] focus:ring-1 focus:ring-[#C0272D] transition-all leading-relaxed"
          ></textarea>
        </div>

        <div className="flex items-center justify-between pt-1">
          <div className="flex items-center gap-2">
            {history.length > 0 && (
              <select
                onChange={(e) => {
                  if (e.target.value) setSql(e.target.value);
                }}
                defaultValue=""
                className="px-3 py-1.5 bg-white border border-[#E0E4E9] rounded-xl text-xs text-[#3D5166] focus:outline-none"
              >
                <option value="" disabled>
                  Historique récent ({history.length})
                </option>
                {history.map((h, i) => (
                  <option key={i} value={h}>
                    {h.length > 50 ? h.slice(0, 50) + '...' : h}
                  </option>
                ))}
              </select>
            )}
          </div>

          <button
            onClick={handleExecute}
            disabled={loading || !sql.trim()}
            className="flex items-center gap-2 px-5 py-2.5 rounded-xl bg-[#C0272D] hover:bg-[#A01F24] text-white text-xs font-bold transition-all shadow-xs disabled:opacity-50"
          >
            <Play className={`size-3.5 ${loading ? 'animate-spin' : ''}`} />
            <span>{loading ? 'Exécution...' : 'Exécuter la Requête SQL'}</span>
          </button>
        </div>
      </div>

      {error && (
        <div className="p-4 rounded-xl bg-red-50 border border-red-200 flex items-start gap-3 text-xs text-red-700">
          <AlertTriangle className="size-5 text-red-600 shrink-0 mt-0.5" />
          <div>
            <div className="font-bold">Erreur SQL / Rejet de Sécurité</div>
            <div className="mt-0.5">{error}</div>
          </div>
        </div>
      )}

      {/* ── Query Results ── */}
      {result && (
        <div className="bg-white border border-[#E0E4E9] rounded-2xl shadow-xs overflow-hidden space-y-0">
          <div className="p-4 bg-[#F8FAFC] border-b border-[#E0E4E9] flex items-center justify-between text-xs">
            <div className="flex items-center gap-3">
              <span className="flex items-center gap-1.5 text-emerald-700 font-bold">
                <CheckCircle2 className="size-4" />
                <span>{result.count} résultat(s)</span>
              </span>
              <span className="text-[#8C9BAE]">•</span>
              <span className="text-[#3D5166]">{result.execution_time_ms} ms</span>
              <span className="text-[#8C9BAE]">•</span>
              <span className="px-2 py-0.5 bg-white border border-[#E0E4E9] text-[#3D5166] rounded text-[11px]">
                {result.engine_mode === 'native' ? 'Moteur Osqueryi' : 'Télémétrie Haute Fidélité'}
              </span>
            </div>

            <div className="flex items-center gap-2">
              <button
                onClick={() => exportResult('csv')}
                className="flex items-center gap-1 px-3 py-1 rounded-lg bg-white hover:bg-[#F4F6F8] border border-[#E0E4E9] text-xs text-[#3D5166]"
              >
                <Download className="size-3.5" />
                <span>CSV</span>
              </button>
              <button
                onClick={() => exportResult('json')}
                className="flex items-center gap-1 px-3 py-1 rounded-lg bg-white hover:bg-[#F4F6F8] border border-[#E0E4E9] text-xs text-[#3D5166]"
              >
                <Download className="size-3.5" />
                <span>JSON</span>
              </button>
            </div>
          </div>

          <div className="overflow-x-auto max-h-[500px]">
            <table className="w-full text-left text-xs font-mono">
              <thead className="bg-[#F4F6F8] sticky top-0 border-b border-[#E0E4E9] text-[#3D5166] uppercase text-[10px] tracking-wider font-bold">
                <tr>
                  {result.columns.map((col) => (
                    <th key={col} className="py-3 px-4 whitespace-nowrap">
                      {col}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-[#E0E4E9]">
                {result.rows.length === 0 ? (
                  <tr>
                    <td colSpan={result.columns.length} className="py-8 text-center text-slate-500 font-sans">
                      Aucune ligne retournée.
                    </td>
                  </tr>
                ) : (
                  result.rows.map((row, rIdx) => (
                    <tr key={rIdx} className="hover:bg-[#F8FAFC] transition-colors">
                      {result.columns.map((col) => (
                        <td key={col} className="py-3 px-4 text-[#0C1825] whitespace-nowrap">
                          {row[col] !== null && row[col] !== undefined ? String(row[col]) : 'NULL'}
                        </td>
                      ))}
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* ── Schema Browser Modal ── */}
      {showSchemaModal && status && (
        <AccessibleModal open title="Catalogue des Tables Osquery & Audit" onClose={() => setShowSchemaModal(false)} className="max-w-3xl">
          <div className="flex max-h-[70vh] flex-col">
            <div className="p-3 my-3 bg-[#F8FAFC] rounded-xl border border-[#E0E4E9] shrink-0">
              <div className="relative">
                <Search className="size-4 text-[#8C9BAE] absolute left-3 top-2.5" />
                <input
                  type="text"
                  value={schemaSearch}
                  onChange={(e) => setSchemaSearch(e.target.value)}
                  placeholder="Rechercher une table ou une colonne..."
                  className="w-full pl-9 pr-3 py-1.5 bg-white border border-[#E0E4E9] rounded-lg text-xs text-[#0C1825] placeholder:text-[#8C9BAE] focus:outline-none focus:border-[#C0272D]"
                />
              </div>
            </div>

            <div className="overflow-y-auto space-y-4 pr-1 text-xs">
              {Object.entries(status.tables)
                .filter(([tbl, cols]) => {
                  if (!schemaSearch) return true;
                  const term = schemaSearch.toLowerCase();
                  if (tbl.toLowerCase().includes(term)) return true;
                  return Object.keys(cols).some((c) => c.toLowerCase().includes(term));
                })
                .map(([tbl, cols]) => (
                  <div key={tbl} className="p-4 rounded-xl bg-[#F8FAFC] border border-[#E0E4E9] space-y-2">
                    <div className="flex items-center justify-between">
                      <span className="font-bold text-[#C0272D] font-mono text-sm">{tbl}</span>
                      <button
                        type="button"
                        onClick={() => {
                          setSql(`SELECT * FROM ${tbl} LIMIT 20;`);
                          setShowSchemaModal(false);
                        }}
                        className="text-xs font-semibold text-[#0C1825] hover:underline"
                      >
                        Générer SELECT &rarr;
                      </button>
                    </div>
                    <div className="flex flex-wrap gap-1.5">
                      {Object.entries(cols).map(([col, type]) => (
                        <span
                          key={col}
                          className="px-2 py-0.5 rounded bg-white border border-[#E0E4E9] text-[11px] font-mono text-[#3D5166]"
                        >
                          {col} <span className="text-[#8C9BAE]">({type})</span>
                        </span>
                      ))}
                    </div>
                  </div>
                ))}
            </div>
          </div>
        </AccessibleModal>
      )}
    </div>
  );
}
