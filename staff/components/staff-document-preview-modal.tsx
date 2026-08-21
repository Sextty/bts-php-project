'use client';

import { useEffect, useState } from 'react';
import {
  Download,
  Eye,
  FileText,
  FileImage,
  FileSpreadsheet,
  File,
  Loader2,
  AlertCircle,
  CheckCircle2,
  XCircle,
  Sparkles,
} from 'lucide-react';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { downloadStaffDocumentBlob } from '@/lib/api/staff';
import type { DocumentDto } from '@/lib/api/credit-applications';

interface StaffDocumentPreviewModalProps {
  applicationId: number;
  document: DocumentDto | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

export function StaffDocumentPreviewModal({
  applicationId,
  document,
  open,
  onOpenChange,
}: StaffDocumentPreviewModalProps) {
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [objectUrl, setObjectUrl] = useState<string | null>(null);
  const [detectedMime, setDetectedMime] = useState<string>('');
  const [activeTab, setActiveTab] = useState<'preview' | 'ai'>('preview');

  useEffect(() => {
    if (!open || !document) {
      if (objectUrl) {
        URL.revokeObjectURL(objectUrl);
        setObjectUrl(null);
      }
      setError(null);
      setLoading(false);
      return;
    }

    let isMounted = true;
    setLoading(true);
    setError(null);

    downloadStaffDocumentBlob(applicationId, document.id)
      .then(({ blob, mimeType }) => {
        if (!isMounted) return;
        const url = URL.createObjectURL(blob);
        setObjectUrl(url);
        setDetectedMime(mimeType || document.mime_type || 'application/octet-stream');
      })
      .catch((err) => {
        if (!isMounted) return;
        setError(err instanceof Error ? err.message : 'Impossible de charger le document.');
      })
      .finally(() => {
        if (isMounted) setLoading(false);
      });

    return () => {
      isMounted = false;
    };
  }, [open, document, applicationId]);

  const handleOpenChange = (nextOpen: boolean) => {
    if (!nextOpen && objectUrl) {
      URL.revokeObjectURL(objectUrl);
      setObjectUrl(null);
    }
    onOpenChange(nextOpen);
  };

  const filename = document?.original_filename ?? 'Document';
  const ext = filename.split('.').pop()?.toLowerCase() ?? '';

  const isImage =
    detectedMime.startsWith('image/') ||
    ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'].includes(ext);

  const isPdf =
    detectedMime.includes('pdf') ||
    ext === 'pdf';

  function handleDownload() {
    if (!objectUrl) return;
    const a = window.document.createElement('a');
    a.href = objectUrl;
    a.download = filename;
    window.document.body.appendChild(a);
    a.click();
    window.document.body.removeChild(a);
  }

  function formatSize(bytes?: number) {
    if (!bytes) return '';
    if (bytes >= 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} Mo`;
    if (bytes >= 1024) return `${Math.round(bytes / 1024)} Ko`;
    return `${bytes} o`;
  }

  const hasExtractedData =
    document?.ai_extracted_fields &&
    Object.keys(document.ai_extracted_fields).length > 0;

  const hasMismatches =
    document?.ai_mismatches && document.ai_mismatches.length > 0;

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="max-w-5xl max-h-[92vh] flex flex-col p-4 sm:p-6 overflow-hidden bg-white">
        <DialogHeader className="pb-3 border-b border-[#E0E4E9]">
          <div className="flex items-center justify-between gap-4 pr-6">
            <div className="flex items-center gap-2.5 min-w-0">
              <div className="size-8 rounded-lg bg-[#FDF2F2] text-[#C0272D] flex items-center justify-center shrink-0">
                {isPdf ? (
                  <FileText className="size-4" />
                ) : isImage ? (
                  <FileImage className="size-4" />
                ) : (
                  <File className="size-4" />
                )}
              </div>
              <div className="min-w-0">
                <DialogTitle className="truncate text-base font-bold text-[#0C1825]">
                  {filename}
                </DialogTitle>
                <DialogDescription className="text-xs text-[#3D5166] flex items-center gap-2 mt-0.5">
                  <span className="font-medium text-[#C0272D]">{document?.document_type ?? 'Pièce justificative'}</span>
                  {document?.size_bytes ? <span>• {formatSize(document.size_bytes)}</span> : null}
                  {document?.uploaded_at && (
                    <span>• Déposé le {new Date(document.uploaded_at).toLocaleDateString('fr-FR')}</span>
                  )}
                </DialogDescription>
              </div>
            </div>

            {/* AI Status Badge */}
            {document && (
              <div className="shrink-0 flex items-center gap-2">
                {document.ai_verified_at ? (
                  <span
                    className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-bold ${
                      document.ai_is_valid
                        ? 'bg-emerald-50 text-emerald-700 border border-emerald-200'
                        : 'bg-red-50 text-red-700 border border-red-200'
                    }`}
                  >
                    {document.ai_is_valid ? (
                      <CheckCircle2 className="size-3.5" />
                    ) : (
                      <XCircle className="size-3.5" />
                    )}
                    <span>{document.ai_is_valid ? 'Conforme IA' : 'Non conforme IA'}</span>
                  </span>
                ) : (
                  <span className="px-2.5 py-1 rounded-md text-xs font-semibold bg-gray-100 text-gray-700">
                    Enregistré
                  </span>
                )}
              </div>
            )}
          </div>

          {/* Navigation Tabs */}
          <div className="flex gap-2 mt-3 pt-2 border-t border-[#E0E4E9]">
            <button
              type="button"
              onClick={() => setActiveTab('preview')}
              className={`px-3 py-1.5 text-xs font-bold rounded-lg transition-colors inline-flex items-center gap-1.5 ${
                activeTab === 'preview'
                  ? 'bg-[#C0272D] text-white'
                  : 'bg-[#F4F6F8] text-[#3D5166] hover:bg-gray-200'
              }`}
            >
              <Eye className="size-3.5" />
              <span>Aperçu du fichier</span>
            </button>
            <button
              type="button"
              onClick={() => setActiveTab('ai')}
              className={`px-3 py-1.5 text-xs font-bold rounded-lg transition-colors inline-flex items-center gap-1.5 ${
                activeTab === 'ai'
                  ? 'bg-[#C0272D] text-white'
                  : 'bg-[#F4F6F8] text-[#3D5166] hover:bg-gray-200'
              }`}
            >
              <Sparkles className="size-3.5 text-amber-500" />
              <span>Données extraites & Analyse IA</span>
              {hasMismatches && (
                <span className="size-2 rounded-full bg-red-500 animate-pulse" />
              )}
            </button>
          </div>
        </DialogHeader>

        {/* Tab Content */}
        <div className="flex-1 min-h-[400px] max-h-[65vh] my-2 overflow-auto rounded-lg bg-[#F4F6F8] border border-[#E0E4E9]">
          {activeTab === 'preview' ? (
            <div className="h-full flex items-center justify-center p-3">
              {loading && (
                <div className="flex flex-col items-center justify-center gap-2 py-16 text-[#3D5166]">
                  <Loader2 className="size-8 animate-spin text-[#C0272D]" />
                  <p className="text-sm font-medium">Chargement du document sécurisé…</p>
                </div>
              )}

              {error && (
                <div className="flex flex-col items-center justify-center gap-2 py-12 text-red-600 text-center max-w-md">
                  <AlertCircle className="size-8" />
                  <p className="text-sm font-bold">{error}</p>
                  <p className="text-xs text-[#3D5166]">
                    Vérifiez que votre session agent est active et que ce dossier appartient bien à votre agence.
                  </p>
                </div>
              )}

              {!loading && !error && objectUrl && (
                <>
                  {isImage ? (
                    <div className="flex items-center justify-center w-full h-full">
                      {/* eslint-disable-next-line @next/next/no-img-element */}
                      <img
                        src={objectUrl}
                        alt={filename}
                        className="max-h-[60vh] max-w-full rounded-md object-contain shadow-sm border border-[#E0E4E9] bg-white"
                      />
                    </div>
                  ) : isPdf ? (
                    <iframe
                      src={objectUrl}
                      title={filename}
                      className="w-full h-[60vh] rounded-md border-0 bg-white shadow-sm"
                    />
                  ) : (
                    <div className="flex flex-col items-center justify-center gap-3 py-16 text-center">
                      <FileSpreadsheet className="size-16 text-gray-400" />
                      <div>
                        <p className="text-sm font-bold text-[#0C1825]">{filename}</p>
                        <p className="text-xs text-[#3D5166] mt-1">
                          Aperçu direct non supporté pour ce format de fichier.
                        </p>
                      </div>
                      <Button onClick={handleDownload} variant="outline" size="sm" className="gap-2 mt-2">
                        <Download className="size-4 text-[#C0272D]" />
                        Télécharger le document
                      </Button>
                    </div>
                  )}
                </>
              )}
            </div>
          ) : (
            <div className="p-4 sm:p-6 space-y-4">
              {/* AI Comment & Confidence */}
              <div className="p-4 bg-white rounded-xl border border-[#E0E4E9] space-y-3">
                <div className="flex items-center justify-between border-b border-[#E0E4E9] pb-2">
                  <div className="flex items-center gap-2">
                    <Sparkles className="size-4 text-[#C0272D]" />
                    <h4 className="text-xs font-bold uppercase tracking-wider text-[#0C1825]">
                      Résultat de l&apos;analyse IA
                    </h4>
                  </div>
                  {document?.ai_confidence && (
                    <span className="text-[11px] font-semibold text-[#3D5166]">
                      Niveau de confiance : <strong className="uppercase text-[#0C1825]">{document.ai_confidence}</strong>
                    </span>
                  )}
                </div>

                {document?.ai_comment ? (
                  <p className="text-xs text-[#1E2D3D] leading-relaxed bg-[#F4F6F8] p-3 rounded-lg border border-[#E0E4E9]">
                    {document.ai_comment}
                  </p>
                ) : (
                  <p className="text-xs text-[#3D5166] italic">Aucune observation détaillée enregistrée.</p>
                )}
              </div>

              {/* Mismatches Alert */}
              {hasMismatches && (
                <div className="p-4 bg-red-50 rounded-xl border border-red-200 space-y-2">
                  <div className="flex items-center gap-2 text-red-800 font-bold text-xs">
                    <AlertCircle className="size-4 text-red-600 shrink-0" />
                    <span>Incohérences détectées ({document!.ai_mismatches!.length}) :</span>
                  </div>
                  <ul className="space-y-1.5 text-xs text-red-900 pl-6 list-disc">
                    {document!.ai_mismatches!.map((m, idx) => (
                      <li key={idx}>
                        <strong>{m.field}</strong> : Reçu <em>&quot;{m.extracted ?? 'vide'}&quot;</em> vs Attendu <em>&quot;{m.expected ?? 'vide'}&quot;</em>
                        {m.severity === 'critical' && <span className="ml-2 font-bold text-red-700">(Bloquant)</span>}
                      </li>
                    ))}
                  </ul>
                </div>
              )}

              {/* Extracted Fields Table */}
              <div className="p-4 bg-white rounded-xl border border-[#E0E4E9] space-y-3">
                <h4 className="text-xs font-bold uppercase tracking-wider text-[#0C1825]">
                  Champs extraits par Reconnaissance Optique (OCR)
                </h4>
                {hasExtractedData ? (
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-1">
                    {Object.entries(document!.ai_extracted_fields!).map(([key, val]) => (
                      <div key={key} className="p-2.5 bg-[#F4F6F8] rounded-lg border border-[#E0E4E9] space-y-0.5">
                        <span className="text-[10px] uppercase font-bold text-[#3D5166] block">
                          {key.replace(/_/g, ' ')}
                        </span>
                        <span className="text-xs font-bold text-[#0C1825] font-mono block">
                          {val !== null && val !== undefined && val !== '' ? String(val) : '—'}
                        </span>
                      </div>
                    ))}
                  </div>
                ) : (
                  <p className="text-xs text-[#3D5166]">Aucun champ textuel spécifique n&apos;a été extrait pour ce document.</p>
                )}
              </div>
            </div>
          )}
        </div>

        <DialogFooter className="pt-3 border-t border-[#E0E4E9] flex items-center justify-between sm:justify-between">
          <Button variant="outline" size="sm" onClick={() => handleOpenChange(false)} className="text-xs">
            Fermer
          </Button>
          {objectUrl && (
            <Button size="sm" onClick={handleDownload} className="btn-red text-xs gap-1.5">
              <Download className="size-3.5" />
              Télécharger le document
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
