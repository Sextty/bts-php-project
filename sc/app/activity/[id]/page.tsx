'use client';

import { useEffect, useState } from 'react';
import { useParams } from 'next/navigation';
import Link from 'next/link';
import {
  ArrowLeft,
  User,
  Laptop,
  Globe,
  AlertCircle,
  Loader2,
} from 'lucide-react';
import { getSecurityActivityDetail, AuditEventItem } from '@/lib/api/security';
import { formatDate } from '@/lib/utils';
import { getErrorMessage } from '@/lib/api/client';

export default function SecurityActivityDetailPage() {
  const params = useParams();
  const id = Number(params?.id);
  const invalidId = !Number.isInteger(id) || id < 1;

  const [event, setEvent] = useState<AuditEventItem | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (invalidId) return;
    const fetchDetail = async () => {
      setLoading(true);
      setError(null);
      try {
        const res = await getSecurityActivityDetail(id);
        setEvent(res.event);
      } catch (fetchError: unknown) {
        setError(getErrorMessage(fetchError, 'Événement introuvable ou service indisponible.'));
      } finally {
        setLoading(false);
      }
    };
    fetchDetail();
  }, [id, invalidId]);

  if (loading && !invalidId) {
    return (
      <div className="flex items-center justify-center min-h-[400px]">
        <Loader2 className="size-8 animate-spin text-[#C0272D]" />
      </div>
    );
  }

  if (invalidId || error || !event) {
    return (
      <div className="max-w-2xl mx-auto mt-12 p-6 rounded-2xl bg-red-50 border border-red-200 text-center">
        <AlertCircle className="size-10 text-red-600 mx-auto mb-3" />
        <h2 className="text-sm font-bold text-red-800">{invalidId ? 'Identifiant d’événement invalide' : error || 'Événement non trouvé'}</h2>
        <Link
          href="/activity"
          className="mt-4 inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-white border border-[#E0E4E9] hover:bg-[#F4F6F8] text-xs font-semibold text-[#0C1825]"
        >
          <ArrowLeft className="size-3.5" />
          <span>Retour au journal</span>
        </Link>
      </div>
    );
  }

  return (
    <div className="sc-page max-w-4xl">
      {/* ── Header ── */}
      <div className="sc-page-header flex items-center justify-between">
        <div className="flex items-center gap-3">
          <Link
            href="/activity"
            aria-label="Retour au journal d’audit"
            className="p-2 rounded-xl bg-white border border-[#E0E4E9] hover:bg-[#F4F6F8] text-[#3D5166] transition-colors shadow-2xs"
          >
            <ArrowLeft className="size-4" />
          </Link>
          <div>
            <h1 className="text-lg font-bold text-[#0C1825] flex items-center gap-2">
              <span>Événement d’Audit #{event.id}</span>
              <span className="px-2.5 py-0.5 text-xs font-mono bg-[#FDF2F2] text-[#C0272D] border border-[#F5C2C4] rounded-lg">
                {event.action}
              </span>
            </h1>
            <p className="text-xs text-[#3D5166] mt-0.5">{formatDate(event.created_at)}</p>
          </div>
        </div>
      </div>

      {/* ── Cards Grid ── */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-6 text-xs">
        {/* Actor Info */}
        <div className="p-6 rounded-2xl bg-white border border-[#E0E4E9] shadow-xs space-y-4">
          <div className="flex items-center gap-2 font-bold text-[#0C1825] border-b border-[#E0E4E9] pb-3">
            <User className="size-4 text-[#C0272D]" />
            <span>Informations Acteur</span>
          </div>
          <div className="space-y-2.5 text-[#3D5166]">
            <div className="flex justify-between">
              <span>Nom :</span>
              <span className="font-bold text-[#0C1825]">{event.actor.name}</span>
            </div>
            <div className="flex justify-between">
              <span>Type d’acteur :</span>
              <span className="font-semibold text-[#0C1825] uppercase">{event.actor.type}</span>
            </div>
            {event.actor.email && (
              <div className="flex justify-between">
                <span>E-mail :</span>
                <span className="text-[#0C1825]">{event.actor.email}</span>
              </div>
            )}
            {event.credit_application_id && (
              <div className="flex justify-between">
                <span>Dossier associé :</span>
                <span className="font-bold text-[#C0272D]">#{event.credit_application_id}</span>
              </div>
            )}
          </div>
        </div>

        {/* Network & Device Info */}
        <div className="p-6 rounded-2xl bg-white border border-[#E0E4E9] shadow-xs space-y-4">
          <div className="flex items-center gap-2 font-bold text-[#0C1825] border-b border-[#E0E4E9] pb-3">
            <Laptop className="size-4 text-cyan-600" />
            <span>Télémétrie Réseau & Connexion</span>
          </div>
          <div className="space-y-2.5 text-[#3D5166]">
            <div className="flex justify-between">
              <span>Adresse IP :</span>
              <span className="font-mono font-bold text-[#0C1825]">{event.ip_address || 'Non spécifiée'}</span>
            </div>
            <div className="flex justify-between">
              <span>Système (OS) :</span>
              <span className="font-semibold text-[#0C1825]">{event.device?.os || 'Non détecté'}</span>
            </div>
            <div className="flex justify-between">
              <span>Navigateur :</span>
              <span className="font-semibold text-[#0C1825]">{event.device?.browser || 'Non détecté'}</span>
            </div>
          </div>
        </div>
      </div>

      {/* ── Raw User Agent ── */}
      {event.user_agent && (
        <div className="p-6 rounded-2xl bg-white border border-[#E0E4E9] shadow-xs space-y-3 text-xs">
          <div className="font-bold text-[#0C1825] flex items-center gap-2">
            <Globe className="size-4 text-indigo-600" />
            <span>En-tête User Agent</span>
          </div>
          <div className="p-3.5 bg-[#F8FAFC] rounded-xl border border-[#E0E4E9] text-[#0C1825] break-all font-mono">
            {event.user_agent}
          </div>
        </div>
      )}

      {/* ── State Changes: Previous State vs New State ── */}
      {(event.previous_state || event.new_state) && (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-6 text-xs">
          <div className="p-6 rounded-2xl bg-white border border-[#E0E4E9] shadow-xs space-y-3">
            <div className="font-bold text-[#0C1825]">État Précédent (previous_state)</div>
            <pre className="p-3.5 bg-[#F8FAFC] rounded-xl border border-[#E0E4E9] text-[#0C1825] font-mono overflow-x-auto max-h-60">
              {event.previous_state ? JSON.stringify(event.previous_state, null, 2) : 'null'}
            </pre>
          </div>
          <div className="p-6 rounded-2xl bg-white border border-[#E0E4E9] shadow-xs space-y-3">
            <div className="font-bold text-[#0C1825]">Nouvel État (new_state)</div>
            <pre className="p-3.5 bg-[#F8FAFC] rounded-xl border border-[#E0E4E9] text-[#0C1825] font-mono overflow-x-auto max-h-60">
              {event.new_state ? JSON.stringify(event.new_state, null, 2) : 'null'}
            </pre>
          </div>
        </div>
      )}
    </div>
  );
}
