'use client';

import { useEffect, useState } from 'react';
import { Download, Eye, FileText, FileImage, FileSpreadsheet, File, Loader2, AlertCircle } from 'lucide-react';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { downloadDocumentBlob, type DocumentDto } from '@/lib/api/credit-applications';

interface DocumentPreviewModalProps {
  applicationId: number;
  document: DocumentDto | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

export function DocumentPreviewModal({
  applicationId,
  document,
  open,
  onOpenChange,
}: DocumentPreviewModalProps) {
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [objectUrl, setObjectUrl] = useState<string | null>(null);
  const [detectedMime, setDetectedMime] = useState<string>('');

  useEffect(() => {
    if (!open || !document) return;

    let isMounted = true;
    let generatedUrl: string | null = null;
    queueMicrotask(() => {
      if (isMounted) {
        setLoading(true);
        setError(null);
      }
    });

    downloadDocumentBlob(applicationId, document.id)
      .then(({ blob, mimeType }) => {
        if (!isMounted) return;
        const url = URL.createObjectURL(blob);
        generatedUrl = url;
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
      if (generatedUrl) URL.revokeObjectURL(generatedUrl);
    };
  }, [open, document, applicationId]);

  // Clean up URL when closing modal
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

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="max-w-4xl max-h-[90vh] flex flex-col p-4 sm:p-6 overflow-hidden">
        <DialogHeader className="pb-2 border-b border-border/60">
          <div className="flex items-center gap-2 pr-6">
            {isPdf ? (
              <FileText className="size-5 text-red-500 shrink-0" />
            ) : isImage ? (
              <FileImage className="size-5 text-blue-500 shrink-0" />
            ) : (
              <File className="size-5 text-muted-foreground shrink-0" />
            )}
            <DialogTitle className="truncate text-base font-semibold">{filename}</DialogTitle>
          </div>
          <DialogDescription className="text-xs text-muted-foreground flex items-center gap-2">
            <span>Type : {document?.document_type ?? 'Justificatif'}</span>
            {document?.size_bytes ? <span>• {formatSize(document.size_bytes)}</span> : null}
          </DialogDescription>
        </DialogHeader>

        <div className="flex-1 min-h-[350px] max-h-[65vh] my-3 flex items-center justify-center overflow-auto rounded-lg bg-muted/20 border border-border/40 p-2">
          {loading && (
            <div className="flex flex-col items-center justify-center gap-2 py-16 text-muted-foreground">
              <Loader2 className="size-8 animate-spin text-primary" />
              <p className="text-sm">Chargement du document…</p>
            </div>
          )}

          {error && (
            <div className="flex flex-col items-center justify-center gap-2 py-12 text-destructive text-center">
              <AlertCircle className="size-8" />
              <p className="text-sm font-medium">{error}</p>
            </div>
          )}

          {!loading && !error && objectUrl && (
            <>
              {isImage ? (
                <div className="flex items-center justify-center w-full h-full p-2">
                  {/* eslint-disable-next-line @next/next/no-img-element */}
                  <img
                    src={objectUrl}
                    alt={filename}
                    className="max-h-[60vh] max-w-full rounded-md object-contain shadow-sm"
                  />
                </div>
              ) : isPdf ? (
                <iframe
                  src={objectUrl}
                  title={filename}
                  className="w-full h-[60vh] rounded-md border-0 bg-white"
                />
              ) : (
                <div className="flex flex-col items-center justify-center gap-3 py-16 text-center">
                  <FileSpreadsheet className="size-16 text-muted-foreground/60" />
                  <div>
                    <p className="text-sm font-medium text-foreground">{filename}</p>
                    <p className="text-xs text-muted-foreground">
                      La prévisualisation intégrée n&apos;est pas disponible pour ce type de fichier.
                    </p>
                  </div>
                  <Button onClick={handleDownload} variant="secondary" size="sm" className="gap-2 mt-2">
                    <Download className="size-4" />
                    Télécharger le document
                  </Button>
                </div>
              )}
            </>
          )}
        </div>

        <DialogFooter className="pt-2 border-t border-border/60 flex items-center justify-between sm:justify-between">
          <Button variant="outline" size="sm" onClick={() => handleOpenChange(false)}>
            Fermer
          </Button>
          {objectUrl && (
            <Button size="sm" onClick={handleDownload} className="gap-2">
              <Download className="size-4" />
              Télécharger
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

export function DocumentItemPreview({
  doc,
  applicationId,
}: {
  doc: DocumentDto;
  applicationId: number;
}) {
  const [modalOpen, setModalOpen] = useState(false);
  const ext = doc.original_filename.split('.').pop()?.toLowerCase() ?? '';
  const isPdf = ext === 'pdf' || doc.mime_type?.includes('pdf');
  const isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'].includes(ext) || doc.mime_type?.startsWith('image/');

  function formatSize(bytes?: number) {
    if (!bytes) return '';
    if (bytes >= 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} Mo`;
    if (bytes >= 1024) return `${Math.round(bytes / 1024)} Ko`;
    return `${bytes} o`;
  }

  return (
    <>
      <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border/60 bg-card p-3 shadow-xs hover:border-primary/40 transition-colors">
        <div className="flex items-center gap-3 min-w-0">
          <div className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-muted/60 text-primary border border-border/50">
            {isPdf ? (
              <FileText className="size-5 text-red-500" />
            ) : isImage ? (
              <FileImage className="size-5 text-blue-500" />
            ) : (
              <File className="size-5 text-muted-foreground" />
            )}
          </div>
          <div className="min-w-0">
            <p className="truncate text-sm font-medium text-foreground" title={doc.original_filename}>
              {doc.original_filename}
            </p>
            <p className="text-xs text-muted-foreground">
              {formatSize(doc.size_bytes)} {doc.size_bytes ? '• ' : ''}
              {doc.uploaded_at ? new Date(doc.uploaded_at).toLocaleDateString() : ''}
            </p>
          </div>
        </div>

        <div className="flex items-center gap-2">
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() => setModalOpen(true)}
            className="gap-1.5 text-xs h-8"
          >
            <Eye className="size-3.5 text-primary" />
            Voir le document
          </Button>
        </div>
      </div>

      <DocumentPreviewModal
        applicationId={applicationId}
        document={doc}
        open={modalOpen}
        onOpenChange={setModalOpen}
      />
    </>
  );
}
