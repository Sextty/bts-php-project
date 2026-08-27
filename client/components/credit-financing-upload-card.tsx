'use client';

import { useState, type ChangeEvent } from 'react';
import {
  Trash2,
  Upload,
  Eye,
  FileText,
  CheckCircle2,
  AlertCircle,
  Wrench,
  Package,
  Building,
  Beef,
  FileSignature,
  Receipt,
  Calculator,
  Percent,
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

const ACCEPTED_FORMATS = '.pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx';
const MAX_FILE_SIZE_MB = 10;
const MAX_FILE_SIZE_BYTES = MAX_FILE_SIZE_MB * 1024 * 1024;

export interface BreakdownAmounts {
  montant_global_sollicite: string | number;
  montant_eqp: string | number;
  montant_fdr: string | number;
  montant_amg: string | number;
  montant_chp: string | number;
}

interface CreditFinancingUploadCardProps {
  applicationId: number;
  documents?: CreditApplicationDto['documents'];
  locked: boolean;
  breakdown: BreakdownAmounts;
  onChangeBreakdown: (newBreakdown: Partial<BreakdownAmounts>) => void;
}

export function CreditFinancingUploadCard({
  applicationId,
  documents,
  locked,
  breakdown,
  onChangeBreakdown,
}: CreditFinancingUploadCardProps) {
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

  // Parse numeric values
  const globalAmount = Number(breakdown.montant_global_sollicite) || 0;
  const eqp = Number(breakdown.montant_eqp) || 0;
  const fdr = Number(breakdown.montant_fdr) || 0;
  const amg = Number(breakdown.montant_amg) || 0;
  const chp = Number(breakdown.montant_chp) || 0;

  const totalAllocated = eqp + fdr + amg + chp;
  const diff = globalAmount - totalAllocated;
  const isMatch = globalAmount > 0 && Math.abs(diff) < 0.01;
  const isOver = diff < -0.01;
  const percentAllocated = globalAmount > 0 ? Math.min(100, Math.round((totalAllocated / globalAmount) * 100)) : 0;

  function filterDocs(types: string[]) {
    return docs.filter((d) => types.includes(d.document_type));
  }

  async function handleUpload(type: string, event: ChangeEvent<HTMLInputElement>) {
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
      {/* ── Header ── */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-[#E0E4E9] pb-4">
        <div className="flex items-center gap-3">
          <div className="size-9 rounded-xl bg-[#FDF2F2] text-[#C0272D] flex items-center justify-center border border-[#FECACA]">
            <Calculator className="size-5" />
          </div>
          <div>
            <h3 className="font-display text-lg font-light text-[#0C1825] flex items-center gap-2">
              Répartition Financière & Pièces Justificatives du Crédit
            </h3>
            <p className="text-xs text-[#3D5166]">
              Indiquez la ventilation détaillée du montant demandé (EQP, FDR, AMG, CHP) et joignez les devis et contrats correspondants.
            </p>
          </div>
        </div>

        <div className="flex items-center gap-2">
          <span className={`text-xs font-mono font-bold px-2.5 py-1 rounded-lg border ${
            isMatch
              ? 'bg-emerald-50 text-emerald-800 border-emerald-300'
              : isOver
              ? 'bg-red-50 text-red-800 border-red-300'
              : 'bg-amber-50 text-amber-800 border-amber-300'
          }`}>
            {totalAllocated.toLocaleString('fr-FR', { minimumFractionDigits: 3 })} / {globalAmount.toLocaleString('fr-FR', { minimumFractionDigits: 3 })} TND
          </span>
        </div>
      </div>

      <div aria-live="polite">
        <ErrorAlert message={errors.length > 0 ? errors.join(' ') : null} />
      </div>

      {/* ── Section 1 : Saisie de la Répartition Financière ── */}
      <div className="space-y-4 bg-[#FAFBFD] p-5 rounded-xl border border-[#E0E4E9]">
        <div className="flex items-center justify-between">
          <h4 className="text-xs font-bold text-[#0C1825] uppercase tracking-wider flex items-center gap-1.5">
            <Percent className="size-4 text-[#C0272D]" />
            <span>1. Ventilation des Composantes de Financement</span>
          </h4>
          <span className="text-[11px] text-[#3D5166]">
            La somme doit être strictement égale à <strong className="text-[#0C1825]">{globalAmount.toLocaleString('fr-FR')} TND</strong>
          </span>
        </div>

        {/* Progress Bar */}
        <div className="space-y-1.5">
          <div className="flex items-center justify-between text-[11px]">
            <span className="font-medium text-[#3D5166]">
              Progression de la répartition : <strong className="text-[#0C1825]">{percentAllocated}%</strong>
            </span>
            {isMatch ? (
              <span className="text-emerald-700 font-bold flex items-center gap-1">
                <CheckCircle2 className="size-3.5" />
                Montant total 100% alloué avec exactitude
              </span>
            ) : isOver ? (
              <span className="text-red-700 font-bold flex items-center gap-1">
                <AlertCircle className="size-3.5" />
                Dépassement de {Math.abs(diff).toLocaleString('fr-FR', { minimumFractionDigits: 3 })} TND
              </span>
            ) : (
              <span className="text-amber-800 font-medium flex items-center gap-1">
                <AlertCircle className="size-3.5" />
                Reste à ventiler : {diff.toLocaleString('fr-FR', { minimumFractionDigits: 3 })} TND
              </span>
            )}
          </div>

          <div className="h-2.5 w-full bg-gray-200 rounded-full overflow-hidden flex">
            <div
              className={`transition-all duration-300 ${isMatch ? 'bg-emerald-600' : isOver ? 'bg-red-600' : 'bg-[#C0272D]'}`}
              style={{ width: `${Math.min(100, percentAllocated)}%` }}
            />
          </div>
        </div>

        {/* 4 Amount Input Cards */}
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 pt-2">
          {/* EQP */}
          <div className="bg-white p-3.5 rounded-xl border border-[#E0E4E9] space-y-2 focus-within:border-purple-500 focus-within:ring-1 focus-within:ring-purple-400">
            <div className="flex items-center gap-2">
              <div className="size-7 rounded-lg bg-purple-50 text-purple-700 border border-purple-200 flex items-center justify-center">
                <Wrench className="size-3.5" />
              </div>
              <div>
                <p className="text-xs font-bold text-[#0C1825]">Équipement (EQP)</p>
                <p className="text-[10px] text-gray-500">Matériel & Machines</p>
              </div>
            </div>
            <div className="relative">
              <input
                type="number"
                min="0"
                step="100"
                disabled={locked}
                value={breakdown.montant_eqp || ''}
                onChange={(e) => onChangeBreakdown({ montant_eqp: e.target.value })}
                placeholder="0.000"
                className="w-full text-xs font-bold font-mono bg-[#F4F6F8] border border-[#E0E4E9] rounded-lg px-2.5 py-2 text-[#0C1825] focus:bg-white focus:outline-none"
              />
              <span className="absolute right-2.5 top-1/2 -translate-y-1/2 text-[10px] font-bold text-gray-500">TND</span>
            </div>
          </div>

          {/* FDR */}
          <div className="bg-white p-3.5 rounded-xl border border-[#E0E4E9] space-y-2 focus-within:border-blue-500 focus-within:ring-1 focus-within:ring-blue-400">
            <div className="flex items-center gap-2">
              <div className="size-7 rounded-lg bg-blue-50 text-blue-700 border border-blue-200 flex items-center justify-center">
                <Package className="size-3.5" />
              </div>
              <div>
                <p className="text-xs font-bold text-[#0C1825]">Fonds Roulement (FDR)</p>
                <p className="text-[10px] text-gray-500">Stock & Exploitation</p>
              </div>
            </div>
            <div className="relative">
              <input
                type="number"
                min="0"
                step="100"
                disabled={locked}
                value={breakdown.montant_fdr || ''}
                onChange={(e) => onChangeBreakdown({ montant_fdr: e.target.value })}
                placeholder="0.000"
                className="w-full text-xs font-bold font-mono bg-[#F4F6F8] border border-[#E0E4E9] rounded-lg px-2.5 py-2 text-[#0C1825] focus:bg-white focus:outline-none"
              />
              <span className="absolute right-2.5 top-1/2 -translate-y-1/2 text-[10px] font-bold text-gray-500">TND</span>
            </div>
          </div>

          {/* AMG */}
          <div className="bg-white p-3.5 rounded-xl border border-[#E0E4E9] space-y-2 focus-within:border-amber-500 focus-within:ring-1 focus-within:ring-amber-400">
            <div className="flex items-center gap-2">
              <div className="size-7 rounded-lg bg-amber-50 text-amber-700 border border-amber-200 flex items-center justify-center">
                <Building className="size-3.5" />
              </div>
              <div>
                <p className="text-xs font-bold text-[#0C1825]">Aménagement (AMG)</p>
                <p className="text-[10px] text-gray-500">Travaux & Agencement</p>
              </div>
            </div>
            <div className="relative">
              <input
                type="number"
                min="0"
                step="100"
                disabled={locked}
                value={breakdown.montant_amg || ''}
                onChange={(e) => onChangeBreakdown({ montant_amg: e.target.value })}
                placeholder="0.000"
                className="w-full text-xs font-bold font-mono bg-[#F4F6F8] border border-[#E0E4E9] rounded-lg px-2.5 py-2 text-[#0C1825] focus:bg-white focus:outline-none"
              />
              <span className="absolute right-2.5 top-1/2 -translate-y-1/2 text-[10px] font-bold text-gray-500">TND</span>
            </div>
          </div>

          {/* CHP */}
          <div className="bg-white p-3.5 rounded-xl border border-[#E0E4E9] space-y-2 focus-within:border-emerald-500 focus-within:ring-1 focus-within:ring-emerald-400">
            <div className="flex items-center gap-2">
              <div className="size-7 rounded-lg bg-emerald-50 text-emerald-700 border border-emerald-200 flex items-center justify-center">
                <Beef className="size-3.5" />
              </div>
              <div>
                <p className="text-xs font-bold text-[#0C1825]">Cheptel (CHP)</p>
                <p className="text-[10px] text-gray-500">Achat d’animaux / Bétail</p>
              </div>
            </div>
            <div className="relative">
              <input
                type="number"
                min="0"
                step="100"
                disabled={locked}
                value={breakdown.montant_chp || ''}
                onChange={(e) => onChangeBreakdown({ montant_chp: e.target.value })}
                placeholder="0.000"
                className="w-full text-xs font-bold font-mono bg-[#F4F6F8] border border-[#E0E4E9] rounded-lg px-2.5 py-2 text-[#0C1825] focus:bg-white focus:outline-none"
              />
              <span className="absolute right-2.5 top-1/2 -translate-y-1/2 text-[10px] font-bold text-gray-500">TND</span>
            </div>
          </div>
        </div>
      </div>

      {/* ── Section 2 : Justificatifs & Pièces jointes du Crédit ── */}
      <div className="space-y-4">
        <h4 className="text-xs font-bold text-[#0C1825] uppercase tracking-wider flex items-center gap-1.5">
          <FileSignature className="size-4 text-[#C0272D]" />
          <span>2. Pièces Justificatives Requises pour le Financement</span>
        </h4>

        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {/* A. Devis & Factures Proforma */}
          <DocumentUploadBox
            title="Devis & Factures Proforma Fournisseurs"
            description="Devis récents, factures proforma des équipements ou stocks"
            icon={<Receipt className="size-4 text-cyan-700" />}
            iconBg="bg-cyan-50 border-cyan-200"
            typeToUpload="devis"
            docs={filterDocs(['devis', 'facture'])}
            uploading={uploading}
            locked={locked}
            onUpload={handleUpload}
            onDelete={handleDelete}
            onPreview={(doc) => {
              setSelectedDoc(doc);
              setPreviewOpen(true);
            }}
            formatSize={formatSize}
          />

          {/* B. Contrat de Location / Bail commercial */}
          <DocumentUploadBox
            title="Contrat de Location / Titre du Local"
            description="Contrat de bail commercial, promesse de location ou titre de propriété"
            icon={<Building className="size-4 text-amber-700" />}
            iconBg="bg-amber-50 border-amber-200"
            typeToUpload="contrat_location"
            docs={filterDocs(['contrat_location', 'contrat'])}
            uploading={uploading}
            locked={locked}
            onUpload={handleUpload}
            onDelete={handleDelete}
            onPreview={(doc) => {
              setSelectedDoc(doc);
              setPreviewOpen(true);
            }}
            formatSize={formatSize}
          />

          {/* C. Justificatif EQP (si montant EQP > 0) */}
          {eqp > 0 && (
            <DocumentUploadBox
              title="Justificatifs Équipement & Matériel (EQP)"
              description="Devis techniques machines, fiches techniques et offres de prix"
              icon={<Wrench className="size-4 text-purple-700" />}
              iconBg="bg-purple-50 border-purple-200"
              typeToUpload="eqp"
              docs={filterDocs(['eqp', 'epr'])}
              uploading={uploading}
              locked={locked}
              onUpload={handleUpload}
              onDelete={handleDelete}
              onPreview={(doc) => {
                setSelectedDoc(doc);
                setPreviewOpen(true);
              }}
              formatSize={formatSize}
            />
          )}

          {/* D. Justificatif FDR (si montant FDR > 0) */}
          {fdr > 0 && (
            <DocumentUploadBox
              title="Justificatifs Fonds de Roulement (FDR)"
              description="Factures proforma matières premières, intrants, emballages"
              icon={<Package className="size-4 text-blue-700" />}
              iconBg="bg-blue-50 border-blue-200"
              typeToUpload="fdr"
              docs={filterDocs(['fdr'])}
              uploading={uploading}
              locked={locked}
              onUpload={handleUpload}
              onDelete={handleDelete}
              onPreview={(doc) => {
                setSelectedDoc(doc);
                setPreviewOpen(true);
              }}
              formatSize={formatSize}
            />
          )}

          {/* E. Justificatif AMG (si montant AMG > 0) */}
          {amg > 0 && (
            <DocumentUploadBox
              title="Devis Aménagement & Travaux (AMG)"
              description="Devis artisans, plans d'aménagement, devis plomberie/électricité"
              icon={<Building className="size-4 text-amber-700" />}
              iconBg="bg-amber-50 border-amber-200"
              typeToUpload="amg"
              docs={filterDocs(['amg'])}
              uploading={uploading}
              locked={locked}
              onUpload={handleUpload}
              onDelete={handleDelete}
              onPreview={(doc) => {
                setSelectedDoc(doc);
                setPreviewOpen(true);
              }}
              formatSize={formatSize}
            />
          )}

          {/* F. Justificatif CHP (si montant CHP > 0) */}
          {chp > 0 && (
            <DocumentUploadBox
              title="Justificatifs Achat de Cheptel (CHP)"
              description="Factures proforma animaux, certificats sanitaires et vétérinaires"
              icon={<Beef className="size-4 text-emerald-700" />}
              iconBg="bg-emerald-50 border-emerald-200"
              typeToUpload="chp"
              docs={filterDocs(['chp'])}
              uploading={uploading}
              locked={locked}
              onUpload={handleUpload}
              onDelete={handleDelete}
              onPreview={(doc) => {
                setSelectedDoc(doc);
                setPreviewOpen(true);
              }}
              formatSize={formatSize}
            />
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

function DocumentUploadBox({
  title,
  description,
  icon,
  iconBg,
  typeToUpload,
  docs,
  uploading,
  locked,
  onUpload,
  onDelete,
  onPreview,
  formatSize,
}: {
  title: string;
  description: string;
  icon: React.ReactNode;
  iconBg: string;
  typeToUpload: string;
  docs: DocumentDto[];
  uploading: string | null;
  locked: boolean;
  onUpload: (type: string, event: ChangeEvent<HTMLInputElement>) => void;
  onDelete: (id: number) => void;
  onPreview: (doc: DocumentDto) => void;
  formatSize: (bytes: number) => string;
}) {
  return (
    <div className="rounded-xl border border-[#E0E4E9] bg-[#FAFBFD] p-4 space-y-3 flex flex-col justify-between">
      <div className="space-y-2">
        <div className="flex items-start justify-between gap-2">
          <div className="flex items-center gap-2">
            <div className={`size-8 rounded-lg border flex items-center justify-center shrink-0 ${iconBg}`}>
              {icon}
            </div>
            <div>
              <h5 className="text-xs font-bold text-[#0C1825]">{title}</h5>
              <p className="text-[10px] text-[#3D5166]">{description}</p>
            </div>
          </div>
          {docs.length > 0 && (
            <span className="inline-flex items-center gap-1 text-[10px] font-bold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full border border-emerald-200 shrink-0">
              <CheckCircle2 className="size-3" />
              {docs.length}
            </span>
          )}
        </div>

        {docs.length > 0 && (
          <ul className="space-y-1.5 pt-1">
            {docs.map((doc) => (
              <li
                key={doc.id}
                className="flex items-center justify-between gap-2 p-2 rounded-lg border border-[#E0E4E9] bg-white text-xs shadow-2xs"
              >
                <div className="flex items-center gap-2 min-w-0">
                  <FileText className="size-3.5 text-[#C0272D] shrink-0" />
                  <div className="min-w-0">
                    <p className="font-semibold text-[#0C1825] truncate text-[11px]">{doc.original_filename}</p>
                    <p className="text-[9px] text-gray-500 font-mono">{formatSize(doc.size_bytes)}</p>
                  </div>
                </div>
                <div className="flex items-center gap-1 shrink-0">
                  <button
                    type="button"
                    onClick={() => onPreview(doc)}
                    className="p-1 text-[#3D5166] hover:text-[#C0272D] rounded hover:bg-[#FDF2F2]"
                    title="Aperçu"
                  >
                    <Eye className="size-3.5" />
                  </button>
                  {!locked && (
                    <button
                      type="button"
                      onClick={() => onDelete(doc.id)}
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

      {!locked && (
        <label className="flex cursor-pointer items-center justify-center gap-2 rounded-lg border border-dashed border-[#E0E4E9] bg-white p-2.5 text-center transition-all hover:border-[#C0272D] hover:bg-[#FDF2F2]/30 group">
          <Upload className="size-3.5 text-[#C0272D] group-hover:scale-110 transition-transform" />
          <span className="text-[11px] font-semibold text-[#0C1825] group-hover:text-[#C0272D]">
            {uploading?.startsWith(`${typeToUpload}-`) ? 'Téléversement…' : 'Joindre un fichier'}
          </span>
          <input
            type="file"
            multiple
            accept={ACCEPTED_FORMATS}
            disabled={uploading !== null}
            onChange={(e) => onUpload(typeToUpload, e)}
            className="sr-only"
          />
        </label>
      )}
    </div>
  );
}
