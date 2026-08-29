'use client';

import { useState, useMemo, type ChangeEvent } from 'react';
import {
  Trash2,
  Upload,
  Eye,
  FileText,
  UploadCloud,
  CheckCircle2,
  Award,
  CreditCard,
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

const ACCEPTED_FORMATS = '.pdf,.jpg,.jpeg,.png,.webp,.doc,.docx';
const MAX_FILE_SIZE_MB = 10;
const MAX_FILE_SIZE_BYTES = MAX_FILE_SIZE_MB * 1024 * 1024;

interface PersonalDocumentsUploadCardProps {
  applicationId: number;
  documents?: CreditApplicationDto['documents'];
  locked: boolean;
}

export function PersonalDocumentsUploadCard({
  applicationId,
  documents,
  locked,
}: PersonalDocumentsUploadCardProps) {
  const [docs, setDocs] = useState<DocumentDto[]>(documents ?? []);
  const [uploading, setUploading] = useState<string | null>(null);
  const [errors, setErrors] = useState<string[]>([]);
  const [selectedDoc, setSelectedDoc] = useState<DocumentDto | null>(null);
  const [previewOpen, setPreviewOpen] = useState(false);

  function formatSize(bytes: number) {
    if (bytes >= 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} Mo`;
    if (bytes >= 1024) return `${Math.round(bytes / 1024)} Ko`;
    return `${bytes} o`;
  }

  // Filter personal docs: CIN and Diplôme
  const cinDocs = useMemo(() => {
    return docs.filter((d) => ['cin', 'passport', 'carte_sejour'].includes(d.document_type));
  }, [docs]);

  const diplomeDocs = useMemo(() => {
    return docs.filter((d) => d.document_type === 'diplome');
  }, [docs]);

  async function handleUpload(type: 'cin' | 'diplome', event: ChangeEvent<HTMLInputElement>) {
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
      setUploading(`${type}-${file.name}`);
      try {
        const { document } = await uploadDocument(applicationId, type, file);
        setDocs((prev) => (prev.some((d) => d.id === document.id) ? prev : [...prev, document]));
      } catch (err) {
        setErrors((prev) => [...prev, err instanceof ApiError ? err.message : `Upload de « ${file.name} » échoué.`]);
      }
    }
    setUploading(null);
  }

  async function handleDelete(docId: number) {
    setErrors([]);
    try {
      await deleteDocument(applicationId, docId);
      setDocs((prev) => prev.filter((d) => d.id !== docId));
    } catch (err) {
      setErrors([err instanceof ApiError ? err.message : 'Impossible de supprimer le document.']);
    }
  }

  return (
    <div className="figma-card p-6 sm:p-8 bg-white space-y-6 mt-6 shadow-xs border border-[#E0E4E9]">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-[#E0E4E9] pb-4">
        <div className="flex items-center gap-3">
          <div className="size-9 rounded-xl bg-[#FDF2F2] text-[#C0272D] flex items-center justify-center border border-[#FECACA]">
            <UploadCloud className="size-5" />
          </div>
          <div>
            <h3 className="font-display text-lg font-light text-[#0C1825] flex items-center gap-2">
              Pièces d’Identité & Diplômes du Demandeur
            </h3>
            <p className="text-xs text-[#3D5166]">
              Joignez votre pièce d’identité (CIN recto/verso) et votre diplôme ou certificat professionnel (max {MAX_FILE_SIZE_MB} Mo par fichier).
            </p>
          </div>
        </div>

        <div className="flex items-center gap-2">
          <span className="text-xs font-mono font-bold text-[#3D5166] bg-[#F4F6F8] px-2.5 py-1 rounded-lg border border-[#E0E4E9]">
            {cinDocs.length + diplomeDocs.length} pièce{(cinDocs.length + diplomeDocs.length) > 1 ? 's' : ''} jointe{(cinDocs.length + diplomeDocs.length) > 1 ? 's' : ''}
          </span>
        </div>
      </div>

      <div aria-live="polite">
        <ErrorAlert message={errors.length > 0 ? errors.join(' ') : null} />
      </div>

      {/* Grid with 2 dedicated upload cards */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
        {/* 1. CIN Card */}
        <div className="rounded-xl border border-[#E0E4E9] bg-[#FAFBFD] p-5 space-y-4 flex flex-col justify-between">
          <div className="space-y-3">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2">
                <div className="size-8 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 flex items-center justify-center">
                  <CreditCard className="size-4" />
                </div>
                <div>
                  <h4 className="text-xs font-bold text-[#0C1825]">
                    1. Carte d’Identité Nationale (CIN) <span className="text-[#C0272D]">*</span>
                  </h4>
                  <p className="text-[11px] text-[#3D5166]">Copie CIN recto-verso ou passeport valide</p>
                </div>
              </div>
              {cinDocs.length > 0 ? (
                <span className="inline-flex items-center gap-1 text-[10px] font-bold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full border border-emerald-200">
                  <CheckCircle2 className="size-3" />
                  {cinDocs.length} joint{cinDocs.length > 1 ? 's' : ''}
                </span>
              ) : (
                <span className="text-[10px] font-bold text-rose-700 bg-rose-50 px-2 py-0.5 rounded-full border border-rose-200">
                  Requis
                </span>
              )}
            </div>

            {/* List of uploaded CIN files */}
            {cinDocs.length > 0 && (
              <ul className="space-y-2 pt-1">
                {cinDocs.map((doc) => (
                  <li
                    key={doc.id}
                    className="flex items-center justify-between gap-2 p-2.5 rounded-lg border border-[#E0E4E9] bg-white text-xs shadow-2xs"
                  >
                    <div className="flex items-center gap-2 min-w-0">
                      <FileText className="size-4 text-[#C0272D] shrink-0" />
                      <div className="min-w-0">
                        <p className="font-semibold text-[#0C1825] truncate text-[11px]">{doc.original_filename}</p>
                        <p className="text-[10px] text-gray-500 font-mono">{formatSize(doc.size_bytes)}</p>
                      </div>
                    </div>
                    <div className="flex items-center gap-1 shrink-0">
                      <button
                        type="button"
                        onClick={() => {
                          setSelectedDoc(doc);
                          setPreviewOpen(true);
                        }}
                        className="p-1 text-[#3D5166] hover:text-[#C0272D] rounded hover:bg-[#FDF2F2]"
                        title="Aperçu"
                      >
                        <Eye className="size-3.5" />
                      </button>
                      {!locked && (
                        <button
                          type="button"
                          onClick={() => handleDelete(doc.id)}
                          className="p-1 text-gray-400 hover:text-red-600 rounded hover:bg-red-50"
                          title="Supprimer"
                        >
                          <Trash2 className="size-3.5" />
                        </button>
                      )}
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </div>

          {/* Upload Button */}
          {!locked && (
            <label className="flex cursor-pointer items-center justify-center gap-2 rounded-lg border-2 border-dashed border-[#E0E4E9] bg-white p-3 text-center transition-all hover:border-[#C0272D] hover:bg-[#FDF2F2]/30 group">
              <Upload className="size-4 text-[#C0272D] group-hover:scale-110 transition-transform" />
              <span className="text-xs font-semibold text-[#0C1825] group-hover:text-[#C0272D]">
                {uploading?.startsWith('cin-') ? 'Téléversement…' : 'Joindre ma CIN (PDF / Image)'}
              </span>
              <input
                type="file"
                multiple
                accept={ACCEPTED_FORMATS}
                disabled={uploading !== null}
                onChange={(e) => handleUpload('cin', e)}
                className="sr-only"
              />
            </label>
          )}
        </div>

        {/* 2. Diplôme Card */}
        <div className="rounded-xl border border-[#E0E4E9] bg-[#FAFBFD] p-5 space-y-4 flex flex-col justify-between">
          <div className="space-y-3">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2">
                <div className="size-8 rounded-lg bg-blue-50 border border-blue-200 text-blue-700 flex items-center justify-center">
                  <Award className="size-4" />
                </div>
                <div>
                  <h4 className="text-xs font-bold text-[#0C1825]">
                    2. Diplôme ou Certificat de Formation <span className="text-[#C0272D]">*</span>
                  </h4>
                  <p className="text-[11px] text-[#3D5166]">Diplôme d’études, BTP/BTS ou attestation de qualification</p>
                </div>
              </div>
              {diplomeDocs.length > 0 ? (
                <span className="inline-flex items-center gap-1 text-[10px] font-bold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full border border-emerald-200">
                  <CheckCircle2 className="size-3" />
                  {diplomeDocs.length} joint{diplomeDocs.length > 1 ? 's' : ''}
                </span>
              ) : (
                <span className="text-[10px] font-bold text-blue-700 bg-blue-50 px-2 py-0.5 rounded-full border border-blue-200">
                  Recommandé
                </span>
              )}
            </div>

            {/* List of uploaded Diplôme files */}
            {diplomeDocs.length > 0 && (
              <ul className="space-y-2 pt-1">
                {diplomeDocs.map((doc) => (
                  <li
                    key={doc.id}
                    className="flex items-center justify-between gap-2 p-2.5 rounded-lg border border-[#E0E4E9] bg-white text-xs shadow-2xs"
                  >
                    <div className="flex items-center gap-2 min-w-0">
                      <FileText className="size-4 text-blue-600 shrink-0" />
                      <div className="min-w-0">
                        <p className="font-semibold text-[#0C1825] truncate text-[11px]">{doc.original_filename}</p>
                        <p className="text-[10px] text-gray-500 font-mono">{formatSize(doc.size_bytes)}</p>
                      </div>
                    </div>
                    <div className="flex items-center gap-1 shrink-0">
                      <button
                        type="button"
                        onClick={() => {
                          setSelectedDoc(doc);
                          setPreviewOpen(true);
                        }}
                        className="p-1 text-[#3D5166] hover:text-[#C0272D] rounded hover:bg-[#FDF2F2]"
                        title="Aperçu"
                      >
                        <Eye className="size-3.5" />
                      </button>
                      {!locked && (
                        <button
                          type="button"
                          onClick={() => handleDelete(doc.id)}
                          className="p-1 text-gray-400 hover:text-red-600 rounded hover:bg-red-50"
                          title="Supprimer"
                        >
                          <Trash2 className="size-3.5" />
                        </button>
                      )}
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </div>

          {/* Upload Button */}
          {!locked && (
            <label className="flex cursor-pointer items-center justify-center gap-2 rounded-lg border-2 border-dashed border-[#E0E4E9] bg-white p-3 text-center transition-all hover:border-blue-500 hover:bg-blue-50/30 group">
              <Upload className="size-4 text-blue-600 group-hover:scale-110 transition-transform" />
              <span className="text-xs font-semibold text-[#0C1825] group-hover:text-blue-700">
                {uploading?.startsWith('diplome-') ? 'Téléversement…' : 'Joindre mon Diplôme (PDF / Image)'}
              </span>
              <input
                type="file"
                multiple
                accept={ACCEPTED_FORMATS}
                disabled={uploading !== null}
                onChange={(e) => handleUpload('diplome', e)}
                className="sr-only"
              />
            </label>
          )}
        </div>
      </div>

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
