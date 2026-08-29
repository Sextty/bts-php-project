'use client';

import { useState } from 'react';
import { Database, Table, X, Search, Terminal, Copy, Check } from 'lucide-react';
import { type OsqueryStatusDto } from '@/lib/api/osquery';

interface OsquerySchemaModalProps {
  status: OsqueryStatusDto | null;
  isOpen: boolean;
  onClose: () => void;
  onSelectQuery: (sql: string) => void;
}

export function OsquerySchemaModal({ status, isOpen, onClose, onSelectQuery }: OsquerySchemaModalProps) {
  const [search, setSearch] = useState('');
  const [selectedTable, setSelectedTable] = useState<string>('system_info');
  const [copiedCol, setCopiedCol] = useState<string | null>(null);

  if (!isOpen || !status) return null;

  const tables = Object.entries(status.tables || {});
  const filteredTables = tables.filter(([name]) => name.toLowerCase().includes(search.toLowerCase()));
  const currentColumns = status.tables?.[selectedTable] || {};

  function handleCopy(text: string) {
    navigator.clipboard.writeText(text);
    setCopiedCol(text);
    setTimeout(() => setCopiedCol(null), 1500);
  }

  function handleUseQuery(table: string) {
    const query = `SELECT * FROM ${table} LIMIT 20;`;
    onSelectQuery(query);
    onClose();
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div className="absolute inset-0 bg-black/50 backdrop-blur-xs" onClick={onClose} />
      <div className="relative bg-white rounded-2xl border border-[#E0E4E9] shadow-2xl max-w-4xl w-full max-h-[85vh] flex flex-col overflow-hidden animate-in fade-in zoom-in-95 duration-150">
        {/* Header */}
        <div className="flex items-center justify-between px-6 py-4 border-b border-[#E0E4E9] bg-white">
          <div className="flex items-center gap-2.5">
            <div className="size-8 rounded-lg bg-red-50 text-[#C0272D] flex items-center justify-center border border-red-200">
              <Database className="size-4" />
            </div>
            <div>
              <h3 className="text-sm font-bold text-[#0C1825]">Schémas & Tables Osquery</h3>
              <p className="text-xs text-[#3D5166]">
                {tables.length} tables SQL disponibles dans le moteur {status.engine_mode === 'native' ? 'Natif Osquery' : 'de Télémétrie BTS'}
              </p>
            </div>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="size-8 rounded-lg flex items-center justify-center text-gray-500 hover:bg-gray-100 hover:text-gray-900 transition-colors"
          >
            <X className="size-4" />
          </button>
        </div>

        {/* Content Body */}
        <div className="flex-1 flex overflow-hidden divide-x divide-[#E0E4E9]">
          {/* Table List Sidebar */}
          <div className="w-1/3 min-w-[220px] bg-[#FAFBFD] flex flex-col p-3 space-y-2">
            <div className="relative">
              <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 size-3.5 text-gray-400" />
              <input
                type="text"
                placeholder="Filtrer les tables..."
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                className="w-full pl-8 pr-3 py-1.5 text-xs bg-white rounded-lg border border-[#E0E4E9] placeholder:text-gray-400 focus:outline-hidden focus:border-[#C0272D]"
              />
            </div>

            <div className="flex-1 overflow-y-auto space-y-1 pr-1">
              {filteredTables.map(([name, cols]) => {
                const isSelected = selectedTable === name;
                return (
                  <button
                    key={name}
                    type="button"
                    onClick={() => setSelectedTable(name)}
                    className={`w-full text-left px-3 py-2 rounded-lg text-xs font-mono transition-all flex items-center justify-between ${
                      isSelected
                        ? 'bg-red-50 text-[#C0272D] font-bold border border-red-200 shadow-2xs'
                        : 'text-[#3D5166] hover:bg-gray-100'
                    }`}
                  >
                    <div className="flex items-center gap-2 truncate">
                      <Table className={`size-3.5 ${isSelected ? 'text-[#C0272D]' : 'text-gray-400'}`} />
                      <span className="truncate">{name}</span>
                    </div>
                    <span className="text-[10px] text-gray-400 font-sans ml-1">
                      {Object.keys(cols).length} col
                    </span>
                  </button>
                );
              })}
            </div>
          </div>

          {/* Table Schema Details */}
          <div className="flex-1 flex flex-col p-5 overflow-y-auto space-y-4">
            <div className="flex items-center justify-between pb-3 border-b border-[#E0E4E9]">
              <div>
                <h4 className="text-sm font-bold font-mono text-[#0C1825] flex items-center gap-2">
                  <span>{selectedTable}</span>
                  <span className="text-[10px] font-sans font-semibold px-2 py-0.5 rounded-full bg-gray-100 text-gray-700">
                    {Object.keys(currentColumns).length} colonnes
                  </span>
                </h4>
                <p className="text-xs text-gray-500 mt-0.5">
                  Table Osquery standard pour interroger les données du système d’exploitation.
                </p>
              </div>

              <button
                type="button"
                onClick={() => handleUseQuery(selectedTable)}
                className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-white bg-[#C0272D] rounded-lg hover:bg-[#A01E23] transition-all shadow-xs"
              >
                <Terminal className="size-3.5" />
                <span>Interroger avec SELECT *</span>
              </button>
            </div>

            {/* Column Table */}
            <div className="border border-[#E0E4E9] rounded-xl overflow-hidden shadow-2xs">
              <table className="w-full text-left text-xs">
                <thead className="bg-[#FAFBFD] border-b border-[#E0E4E9] text-gray-600 font-semibold uppercase tracking-wider text-[10px]">
                  <tr>
                    <th className="px-3.5 py-2.5">Nom de la colonne</th>
                    <th className="px-3.5 py-2.5">Type SQL</th>
                    <th className="px-3.5 py-2.5 text-right">Action</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-[#E0E4E9] font-mono">
                  {Object.entries(currentColumns).map(([colName, colType]) => (
                    <tr key={colName} className="hover:bg-[#FAFBFD] transition-colors">
                      <td className="px-3.5 py-2 font-semibold text-[#0C1825]">{colName}</td>
                      <td className="px-3.5 py-2">
                        <span className="px-2 py-0.5 rounded text-[10px] font-bold bg-blue-50 text-blue-700 border border-blue-200">
                          {colType}
                        </span>
                      </td>
                      <td className="px-3.5 py-2 text-right">
                        <button
                          type="button"
                          onClick={() => handleCopy(colName)}
                          className="p-1 rounded text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-colors inline-flex items-center"
                          title="Copier le nom"
                        >
                          {copiedCol === colName ? (
                            <Check className="size-3 text-emerald-600" />
                          ) : (
                            <Copy className="size-3" />
                          )}
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {/* Example Queries */}
            <div className="bg-[#F8FAFC] border border-[#E0E4E9] rounded-xl p-3.5 space-y-2">
              <p className="text-[11px] font-bold uppercase tracking-wider text-gray-600">Exemple de requête</p>
              <div className="flex items-center justify-between bg-white p-2 rounded-lg border border-[#E0E4E9] font-mono text-xs text-gray-800">
                <code>SELECT * FROM {selectedTable} LIMIT 10;</code>
                <button
                  type="button"
                  onClick={() => handleCopy(`SELECT * FROM ${selectedTable} LIMIT 10;`)}
                  className="text-xs font-sans text-[#C0272D] font-semibold hover:underline flex items-center gap-1"
                >
                  <Copy className="size-3" />
                  Copier
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
