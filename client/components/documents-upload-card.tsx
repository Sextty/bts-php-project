'use client';

import { useEffect, useState, useMemo, type ChangeEvent } from 'react';
import {
  Trash2,
  Upload,
  Eye,
  FileText,
  FileImage,
  File,
  UploadCloud,
  CheckCircle2,
  CheckSquare,
  Square,
  Sparkles,
  Layers,
  Check,
  Plus,
  AlertCircle,
} from 'lucide-react';
import { ErrorAlert } from '@/components/error-alert';
import {
  uploadDocument,
  deleteDocument,
  type CreditApplicationDto,
  type DocumentDto,
} from '@/lib/api/credit-applications';
import { ApiError } from '@/lib/api/client';
import { DocumentPreviewModal } from '@/components/document-preview-modal';

const ACCEPTED_FORMATS = '.pdf,.jpg,.jpeg,.png,.gif,.webp,.doc,.docx,.xls,.xlsx,.csv,.txt,.ppt,.pptx';
const MAX_FILE_SIZE_MB = 10;
const MAX_FILE_SIZE_BYTES = MAX_FILE_SIZE_MB * 1024 * 1024;

export interface CategoryInfo {
  id: string;
  code: string;
  label: string;
  desc: string;
  color: {
    bg: string;
    text: string;
    border: string;
    badgeBg: string;
  };
}

export const DOCUMENT_CATEGORIES: CategoryInfo[] = [
  {
    id: 'fdr',
    code: 'FDR',
    label: 'Fonds de Roulement',
    desc: 'Factures proforma, besoins d’exploitation, stock initial',
    color: { bg: 'bg-blue-50/70', text: 'text-blue-800', border: 'border-blue-200', badgeBg: 'bg-blue-100 text-blue-800 border-blue-200' },
  },
  {
    id: 'amg',
    code: 'AMG',
    label: 'Aménagement & Travaux',
    desc: 'Devis travaux, contrat de bail commercial, plans',
    color: { bg: 'bg-amber-50/70', text: 'text-amber-800', border: 'border-amber-200', badgeBg: 'bg-amber-100 text-amber-800 border-amber-200' },
  },
  {
    id: 'chp',
    code: 'CHP',
    label: 'Achat de Cheptel',
    desc: 'Factures proforma animaux, certificats vétérinaires',
    color: { bg: 'bg-emerald-50/70', text: 'text-emerald-800', border: 'border-emerald-200', badgeBg: 'bg-emerald-100 text-emerald-800 border-emerald-200' },
  },
  {
    id: 'epr',
    code: 'EPR',
    label: 'Équipement Professionnel',
    desc: 'Factures proforma machines, devis matériel, véhicules',
    color: { bg: 'bg-purple-50/70', text: 'text-purple-800', border: 'border-purple-200', badgeBg: 'bg-purple-100 text-purple-800 border-purple-200' },
  },
  {
    id: 'cin',
    code: 'CIN',
    label: 'Pièce d’Identité',
    desc: 'Copie CIN recto-verso ou passeport valide',
    color: { bg: 'bg-rose-50/70', text: 'text-rose-800', border: 'border-rose-200', badgeBg: 'bg-rose-100 text-rose-800 border-rose-200' },
  },
  {
    id: 'facture',
    code: 'DEVIS',
    label: 'Devis & Factures Proforma',
    desc: 'Devis fournisseurs généraux et offres de prix',
    color: { bg: 'bg-cyan-50/70', text: 'text-cyan-800', border: 'border-cyan-200', badgeBg: 'bg-cyan-100 text-cyan-800 border-cyan-200' },
  },
  {
    id: 'other',
    code: 'AUTRE',
    label: 'Autres Justificatifs',
    desc: 'Statuts de société, attestations, diplômes, RNE',
    color: { bg: 'bg-slate-50/70', text: 'text-slate-800', border: 'border-slate-200', badgeBg: 'bg-slate-100 text-slate-800 border-slate-200' },
  },
];

export function DocumentsUploadCard({
  applicationId,
  documents,
  locked,
}: {
  applicationId: number;
  documents?: CreditApplicationDto['documents'];
  locked: boolean;
}) {
  const [docs, setDocs] = useState<DocumentDto[]>(documents ?? []);
  // Multi-selection of categories selected by the user for their credit request
  const [selectedCategories, setSelectedCategories] = useState<string[]>(['fdr']);
  // Current active category for the upload dropzone
  const [activeUploadCategory, setActiveUploadCategory] = useState<string>('fdr');
  const [uploading, setUploading] = useState<string | null>(null);
  const [errors, setErrors] = useState<string[]>([]);
  const [selectedDoc, setSelectedDoc] = useState<DocumentDto | null>(null);
  const [previewOpen, setPreviewOpen] = useState(false);
  const [filterDocCategory, setFilterDocCategory] = useState<string>('all');

  useEffect(() => {
    setDocs(documents ?? []);
  }, [documents]);

  function formatSize(bytes: number) {
    if (bytes >= 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} Mo`;
    if (bytes >= 1024) return `${Math.round(bytes / 1024)} Ko`;
    return `${bytes} o`;
  }

  function getCategoryInfo(type: string): CategoryInfo {
    const found = DOCUMENT_CATEGORIES.find((c) => c.id === type);
    return (
      found ?? {
        id: type,
        code: type.toUpperCase(),
        label: type.toUpperCase(),
        desc: 'Document justificatif',
        color: { bg: 'bg-slate-50', text: 'text-slate-800', border: 'border-slate-200', badgeBg: 'bg-slate-100 text-slate-800 border-slate-200' },
      }
    );
  }

  function toggleCategory(catId: string) {
    if (locked) return;
    setSelectedCategories((prev) => {
      if (prev.includes(catId)) {
        if (prev.length === 1) return prev; // Keep at least one category selected
        const next = prev.filter((id) => id !== catId);
        if (activeUploadCategory === catId) {
          setActiveUploadCategory(next[0] || 'fdr');
        }
        return next;
      } else {
        const next = [...prev, catId];
        setActiveUploadCategory(catId);
        return next;
      }
    });
  }

  // Count docs uploaded per category
  const docCountsByCategory = useMemo(() => {
    const counts: Record<string, number> = {};
    for (const doc of docs) {
      counts[doc.document_type] = (counts[doc.document_type] || 0) + 1;
    }
    return counts;
  }, [docs]);

  // Filtered documents list
  const filteredDocs = useMemo(() => {
    if (filterDocCategory === 'all') return docs;
    return docs.filter((d) => d.document_type === filterDocCategory);
  }, [docs, filterDocCategory]);

  async function handleUpload(event: ChangeEvent<HTMLInputElement>) {
    const files = Array.from(event.target.files ?? []);
    event.target.value = '';
    if (files.length === 0) return;

    const rejected = files.filter((file) => file.size > MAX_FILE_SIZE_BYTES);
    const oversized = new Set(rejected.map((file) => file.name));
    if (oversized.size > 0) {
      setErrors([...oversized].map((name) => `« ${name} » dépasse la limite autorisée de ${MAX_FILE_SIZE_MB} Mo.`));
    } else {
      setErrors([]);
    }

    for (const file of files) {
      if (oversized.has(file.name)) continue;
      setUploading(file.name);
      try {
        const { document } = await uploadDocument(applicationId, activeUploadCategory, file);
        setDocs((prev) => (prev.some((d) => d.id === document.id) ? prev : [...prev, document]));
        // Make sure the category is marked as selected if not already
        setSelectedCategories((prev) => (prev.includes(activeUploadCategory) ? prev : [...prev, activeUploadCategory]));
      } catch (err) {
        setErrors((prev) => [...prev, err instanceof ApiError ? err.message : `Upload de « ${file.name} » échoué.`]);
      }
    }
    setUploading(null);
  }

  async function handleDeleteDocument(documentId: number) {
    setErrors([]);
    try {
      await deleteDocument(applicationId, documentId);
      setDocs((prev) => prev.filter((d) => d.id !== documentId));
    } catch (err) {
      setErrors([err instanceof ApiError ? err.message : 'Impossible de supprimer le document.']);
    }
  }

  return (
    <div className="figma-card p-6 sm:p-8 bg-white space-y-6 mt-6 shadow-xs">
      {/* ── 1. Card Header ── */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-[#E0E4E9] pb-4">
        <div className="flex items-center gap-3">
          <div className="size-9 rounded-xl bg-[#FDF2F2] text-[#C0272D] flex items-center justify-center border border-[#FECACA]">
            <UploadCloud className="size-5" />
          </div>
          <div>
            <h3 className="font-display text-lg font-light text-[#0C1825] flex items-center gap-2">
              Documents & Pièces Justificatives
            </h3>
            <p className="text-xs text-[#3D5166]">
              Sélectionnez les catégories de financement nécessaires et joignez les justificatifs correspondants (max {MAX_FILE_SIZE_MB} Mo / fichier).
            </p>
          </div>
        </div>

        <div className="flex items-center gap-2">
          <span className="text-xs font-mono font-bold text-[#3D5166] bg-[#F4F6F8] px-2.5 py-1 rounded-lg border border-[#E0E4E9]">
            {docs.length} document{docs.length > 1 ? 's' : ''} joint{docs.length > 1 ? 's' : ''}
          </span>
        </div>
      </div>

      <div aria-live="polite">
        <ErrorAlert message={errors.length > 0 ? errors.join(' ') : null} />
      </div>

      {/* ── 2. Multi-Category Selection Grid ── */}
      <div className="space-y-3">
        <div className="flex items-center justify-between">
          <label className="text-xs font-bold uppercase tracking-wider text-[#0C1825] flex items-center gap-1.5">
            <Layers className="size-3.5 text-[#C0272D]" />
            1. Catégories de Financement & Justificatifs Demandés
            <span className="text-[#C0272D]">*</span>
          </label>
          <span className="text-[11px] text-[#3D5166]">
            {selectedCategories.length} sélectionnée{selectedCategories.length > 1 ? 's' : ''} (plusieurs choix possibles)
          </span>
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2.5">
          {DOCUMENT_CATEGORIES.map((cat) => {
            const isSelected = selectedCategories.includes(cat.id);
            const count = docCountsByCategory[cat.id] || 0;
            return (
              <button
                key={cat.id}
                type="button"
                disabled={locked}
                onClick={() => toggleCategory(cat.id)}
                className={`flex items-start gap-3 p-3 rounded-xl border text-left transition-all ${
                  isSelected
                    ? 'border-[#C0272D] bg-[#FDF2F2]/40 shadow-xs ring-1 ring-[#C0272D]/20'
                    : 'border-[#E0E4E9] bg-white hover:bg-[#F4F6F8] hover:border-gray-300'
                } ${locked ? 'cursor-default opacity-85' : 'cursor-pointer'}`}
              >
                <div className="pt-0.5 shrink-0">
                  {isSelected ? (
                    <div className="size-4 rounded bg-[#C0272D] text-white flex items-center justify-center">
                      <Check className="size-3 stroke-[3]" />
                    </div>
                  ) : (
                    <div className="size-4 rounded border border-[#E0E4E9] bg-white" />
                  )}
                </div>

                <div className="min-w-0 flex-1 space-y-1">
                  <div className="flex items-center justify-between gap-1">
                    <span className="text-xs font-bold text-[#0C1825] truncate">{cat.label}</span>
                    <span className={`text-[10px] font-mono font-bold px-1.5 py-0.2 rounded border ${
                      count > 0
                        ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
                        : 'bg-[#F4F6F8] text-[#3D5166] border-[#E0E4E9]'
                    }`}>
                      {count > 0 ? `${count} fichier${count > 1 ? 's' : ''}` : cat.code}
                    </span>
                  </div>
                  <p className="text-[11px] text-[#3D5166] leading-tight line-clamp-2">{cat.desc}</p>
                </div>
              </button>
            );
          })}
        </div>
      </div>

      {/* ── 3. Active Upload Dropzone ── */}
      {!locked && (
        <div className="space-y-3 pt-2">
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
            <span className="text-xs font-bold uppercase tracking-wider text-[#0C1825] flex items-center gap-1.5">
              <Upload className="size-3.5 text-[#C0272D]" />
              2. Téléverser des Pièces pour la Catégorie Active :
            </span>

            {/* Quick Category Switcher Pills */}
            <div className="flex items-center gap-1 overflow-x-auto pb-1">
              {selectedCategories.map((catId) => {
                const cat = getCategoryInfo(catId);
                const isActive = activeUploadCategory === catId;
                return (
                  <button
                    key={catId}
                    type="button"
                    onClick={() => setActiveUploadCategory(catId)}
                    className={`inline-flex items-center gap-1 text-[11px] font-semibold px-2.5 py-1 rounded-lg border transition-all ${
                      isActive
                        ? 'bg-[#C0272D] text-white border-[#C0272D] shadow-xs'
                        : 'bg-[#F4F6F8] text-[#3D5166] border-[#E0E4E9] hover:bg-gray-200'
                    }`}
                  >
                    <span>{cat.code}</span>
                    {docCountsByCategory[catId] ? (
                      <span className={`size-4 rounded-full text-[9px] flex items-center justify-center font-bold ${
                        isActive ? 'bg-white text-[#C0272D]' : 'bg-[#C0272D] text-white'
                      }`}>
                        {docCountsByCategory[catId]}
                      </span>
                    ) : null}
                  </button>
                );
              })}
            </div>
          </div>

          <label className="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-[#E0E4E9] bg-[#F4F6F8]/60 p-6 text-center transition-all hover:border-[#C0272D] hover:bg-[#FDF2F2]/30 group">
            <div className="size-11 rounded-full bg-white shadow-xs border border-[#E0E4E9] flex items-center justify-center text-[#C0272D] group-hover:scale-105 transition-transform">
              <Upload className="size-5" />
            </div>
            <div className="space-y-1">
              <p className="text-xs font-bold text-[#0C1825] group-hover:text-[#C0272D] transition-colors">
                {uploading
                  ? `Téléversement de « ${uploading} » en cours…`
                  : `Ajouter des fichiers pour « ${getCategoryInfo(activeUploadCategory).label} »`}
              </p>
              <p className="text-[11px] text-[#3D5166]">
                Glissez-déposez un ou plusieurs fichiers, ou cliquez pour parcourir.
              </p>
              <p className="text-[10px] text-gray-400">
                Formats acceptés : PDF, JPG, PNG, Word, Excel, PowerPoint (max {MAX_FILE_SIZE_MB} Mo par fichier)
              </p>
            </div>
            <input
              type="file"
              multiple
              accept={ACCEPTED_FORMATS}
              disabled={uploading !== null}
              onChange={handleUpload}
              className="sr-only"
            />
          </label>
        </div>
      )}

      {/* ── 4. Uploaded Documents List & Filters ── */}
      {docs.length > 0 && (
        <div className="space-y-3 pt-2 border-t border-[#E0E4E9]">
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
            <h4 className="text-xs font-bold uppercase tracking-wider text-[#0C1825] flex items-center gap-1.5">
              <FileText className="size-3.5 text-[#C0272D]" />
              Pièces Justificatives Téléversées ({filteredDocs.length})
            </h4>

            {/* Filter by Category */}
            <div className="flex items-center gap-1 overflow-x-auto pb-1 text-xs">
              <button
                type="button"
                onClick={() => setFilterDocCategory('all')}
                className={`text-[10px] font-semibold px-2 py-0.5 rounded-md border ${
                  filterDocCategory === 'all'
                    ? 'bg-[#0C1825] text-white border-[#0C1825]'
                    : 'bg-[#F4F6F8] text-[#3D5166] border-[#E0E4E9] hover:bg-gray-200'
                }`}
              >
                Tous ({docs.length})
              </button>
              {DOCUMENT_CATEGORIES.filter((c) => docCountsByCategory[c.id] > 0).map((cat) => (
                <button
                  key={cat.id}
                  type="button"
                  onClick={() => setFilterDocCategory(cat.id)}
                  className={`text-[10px] font-semibold px-2 py-0.5 rounded-md border ${
                    filterDocCategory === cat.id
                      ? 'bg-[#C0272D] text-white border-[#C0272D]'
                      : 'bg-[#F4F6F8] text-[#3D5166] border-[#E0E4E9] hover:bg-gray-200'
                  }`}
                >
                  {cat.code} ({docCountsByCategory[cat.id]})
                </button>
              ))}
            </div>
          </div>

          <ul className="space-y-2">
            {filteredDocs.map((doc) => {
              const isImg = doc.mime_type.startsWith('image/');
              const isPdf = doc.mime_type === 'application/pdf';
              const Icon = isImg ? FileImage : isPdf ? FileText : File;
              const cat = getCategoryInfo(doc.document_type);

              return (
                <li
                  key={doc.id}
                  className="flex items-center justify-between gap-3 p-3 rounded-xl border border-[#E0E4E9] bg-white hover:border-[#C0272D]/40 transition-colors shadow-2xs"
                >
                  <div className="flex items-center gap-3 min-w-0">
                    <div className="size-9 rounded-lg bg-[#FDF2F2] text-[#C0272D] flex items-center justify-center shrink-0 border border-[#FECACA]">
                      <Icon className="size-4" />
                    </div>
                    <div className="min-w-0 space-y-0.5">
                      <div className="flex items-center gap-2 flex-wrap">
                        <p className="text-xs font-semibold text-[#0C1825] truncate">{doc.original_filename}</p>
                        <span className={`text-[10px] font-bold px-2 py-0.5 rounded border ${cat.color.badgeBg} shrink-0`}>
                          {cat.label} ({cat.code})
                        </span>
                      </div>
                      <p className="text-[10px] text-[#3D5166] font-mono">{formatSize(doc.size_bytes)}</p>
                    </div>
                  </div>

                  <div className="flex items-center gap-1.5 shrink-0">
                    <button
                      type="button"
                      onClick={() => {
                        setSelectedDoc(doc);
                        setPreviewOpen(true);
                      }}
                      className="p-1.5 text-[#3D5166] hover:text-[#C0272D] rounded-lg hover:bg-[#FDF2F2] transition-colors"
                      title="Aperçu du document"
                    >
                      <Eye className="size-4" />
                    </button>

                    {!locked && (
                      <button
                        type="button"
                        onClick={() => handleDeleteDocument(doc.id)}
                        className="p-1.5 text-gray-400 hover:text-red-600 rounded-lg hover:bg-red-50 transition-colors"
                        title="Supprimer"
                      >
                        <Trash2 className="size-4" />
                      </button>
                    )}
                  </div>
                </li>
              );
            })}
          </ul>
        </div>
      )}

      {/* Preview Modal */}
      <DocumentPreviewModal
        applicationId={applicationId}
        document={selectedDoc}
        open={previewOpen}
        onOpenChange={setPreviewOpen}
      />
    </div>
  );
}