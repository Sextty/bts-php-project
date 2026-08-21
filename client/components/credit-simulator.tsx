'use client';

import React, { useState, useId } from 'react';
import Link from 'next/link';
import { ArrowRight, Calculator, Sparkles, TrendingUp, Info } from 'lucide-react';

const PRESET_AMOUNTS = [10000, 25000, 50000, 100000];
const PRESET_DURATIONS = [12, 24, 36, 60, 84];

const LOAN_TYPES = [
  { id: 'pro', label: 'Crédit Professionnel', rate: 8.5 },
  { id: 'invest', label: 'Investissement', rate: 8.0 },
  { id: 'gestion', label: 'Crédit de Gestion', rate: 9.0 },
  { id: 'islamic', label: 'Finance Islamique (Mourabaha)', rate: 8.2 },
];

export function CreditSimulator() {
  const [amount, setAmount] = useState<number>(50000);
  const [durationMonths, setDurationMonths] = useState<number>(60);
  const [loanType, setLoanType] = useState<string>('pro');
  const [showDetails, setShowDetails] = useState<boolean>(false);
  const loanTypeId = useId();

  const selectedType = LOAN_TYPES.find((t) => t.id === loanType) || LOAN_TYPES[0];
  const annualRate = selectedType.rate;

  // Monthly payment calculation: M = P * [r(1+r)^n] / [(1+r)^n - 1]
  const monthlyRate = annualRate / 100 / 12;
  const numPayments = durationMonths;

  let monthlyPayment = 0;
  if (monthlyRate > 0 && numPayments > 0) {
    const factor = Math.pow(1 + monthlyRate, numPayments);
    monthlyPayment = Math.round((amount * monthlyRate * factor) / (factor - 1));
  } else {
    monthlyPayment = Math.round(amount / numPayments);
  }

  const totalRepaid = monthlyPayment * numPayments;
  const totalInterest = Math.max(0, totalRepaid - amount);

  // Formatting helpers
  const formatCurrency = (val: number) => {
    return new Intl.NumberFormat('fr-TN', {
      maximumFractionDigits: 0,
    }).format(val) + ' TND';
  };

  // Slider progress percentages for CSS styling
  const minAmount = 5000;
  const maxAmount = 150000;
  const amountPercent = Math.min(
    100,
    Math.max(0, ((amount - minAmount) / (maxAmount - minAmount)) * 100)
  );

  const minDuration = 12;
  const maxDuration = 84;
  const durationPercent = Math.min(
    100,
    Math.max(0, ((durationMonths - minDuration) / (maxDuration - minDuration)) * 100)
  );

  return (
    <div className="figma-card bg-white border border-[#E0E4E9] rounded-2xl shadow-xl p-5 sm:p-6 relative overflow-hidden transition-all">
      {/* Top Header */}
      <div className="flex items-center justify-between border-b border-[#E0E4E9] pb-3 mb-4">
        <div className="flex items-center gap-2.5">
          <div className="size-7 rounded-lg bg-[#FDF2F2] text-[#C0272D] flex items-center justify-center">
            <Calculator className="size-4" />
          </div>
          <div>
            <h3 className="text-sm font-semibold text-[#0C1825]">Simulateur de Crédit</h3>
            <p className="text-[10px] text-[#3D5166]">Calcul en temps réel selon vos besoins</p>
          </div>
        </div>
        <span className="inline-flex items-center gap-1 text-[10px] font-semibold text-[#C0272D] bg-[#FDF2F2] px-2 py-0.5 rounded-full border border-[#FECACA]">
          <Sparkles className="size-3" /> Taux {annualRate}%
        </span>
      </div>

      <div className="space-y-4">
        {/* 1. Loan Type Selection */}
        <div className="space-y-1.5">
          <label htmlFor={loanTypeId} className="text-xs font-medium text-[#3D5166]">Type de Financement</label>
          <select
            id={loanTypeId}
            value={loanType}
            onChange={(e) => setLoanType(e.target.value)}
            className="w-full text-xs font-medium bg-[#F4F6F8] border border-[#E0E4E9] rounded-lg px-3 py-2 text-[#0C1825] focus:outline-none focus:border-[#C0272D] focus:ring-1 focus:ring-[#C0272D]"
          >
            {LOAN_TYPES.map((t) => (
              <option key={t.id} value={t.id}>
                {t.label} (Taux indicatif : {t.rate}%)
              </option>
            ))}
          </select>
        </div>

        {/* 2. Amount Slider */}
        <div className="space-y-2">
          <div className="flex justify-between items-center text-xs">
            <span className="font-medium text-[#3D5166]">Montant du financement</span>
            <span className="font-bold text-[#0C1825] font-mono text-sm bg-[#F4F6F8] px-2 py-0.5 rounded border border-[#E0E4E9]">
              {formatCurrency(amount)}
            </span>
          </div>

          <div className="relative pt-1">
            <input
              type="range"
              min={minAmount}
              max={maxAmount}
              step={1000}
              value={amount}
              onChange={(e) => setAmount(Number(e.target.value))}
              aria-label="Montant du financement"
              className="w-full h-2 bg-[#E0E4E9] rounded-lg appearance-none cursor-pointer accent-[#C0272D]"
              style={{
                background: `linear-gradient(to right, #C0272D 0%, #C0272D ${amountPercent}%, #E0E4E9 ${amountPercent}%, #E0E4E9 100%)`,
              }}
            />
          </div>

          {/* Quick Amount Chips */}
          <div className="flex gap-1.5 pt-1">
            {PRESET_AMOUNTS.map((amt) => (
              <button
                key={amt}
                type="button"
                onClick={() => setAmount(amt)}
                className={`text-[10px] font-medium px-2 py-0.5 rounded transition-colors ${
                  amount === amt
                    ? 'bg-[#C0272D] text-white'
                    : 'bg-[#F4F6F8] text-[#3D5166] hover:bg-[#E0E4E9]'
                }`}
              >
                {amt >= 1000 ? `${amt / 1000}k TND` : `${amt} TND`}
              </button>
            ))}
          </div>
        </div>

        {/* 3. Duration Slider */}
        <div className="space-y-2">
          <div className="flex justify-between items-center text-xs">
            <span className="font-medium text-[#3D5166]">Durée de remboursement</span>
            <span className="font-bold text-[#0C1825] font-mono text-sm bg-[#F4F6F8] px-2 py-0.5 rounded border border-[#E0E4E9]">
              {durationMonths} mois <span className="text-[10px] text-[#3D5166] font-normal">({(durationMonths / 12).toFixed(1)} ans)</span>
            </span>
          </div>

          <div className="relative pt-1">
            <input
              type="range"
              min={minDuration}
              max={maxDuration}
              step={6}
              value={durationMonths}
              onChange={(e) => setDurationMonths(Number(e.target.value))}
              aria-label="Durée de remboursement en mois"
              className="w-full h-2 bg-[#E0E4E9] rounded-lg appearance-none cursor-pointer accent-[#C0272D]"
              style={{
                background: `linear-gradient(to right, #C0272D 0%, #C0272D ${durationPercent}%, #E0E4E9 ${durationPercent}%, #E0E4E9 100%)`,
              }}
            />
          </div>

          {/* Quick Duration Chips */}
          <div className="flex gap-1.5 pt-1">
            {PRESET_DURATIONS.map((dur) => (
              <button
                key={dur}
                type="button"
                onClick={() => setDurationMonths(dur)}
                className={`text-[10px] font-medium px-2 py-0.5 rounded transition-colors ${
                  durationMonths === dur
                    ? 'bg-[#C0272D] text-white'
                    : 'bg-[#F4F6F8] text-[#3D5166] hover:bg-[#E0E4E9]'
                }`}
              >
                {dur} mois
              </button>
            ))}
          </div>
        </div>

        {/* 4. Calculated Monthly Payment Box */}
        <div className="mt-4 bg-[#FDF2F2] border border-[#FECACA] rounded-xl p-4 text-center relative overflow-hidden">
          <p className="text-[10px] uppercase tracking-wider text-[#C0272D] mb-1 font-bold">
            Mensualité estimée
          </p>
          <div className="flex items-baseline justify-center gap-1.5 text-[#0C1825]">
            <span className="text-3xl sm:text-4xl font-display font-light text-[#C0272D] leading-none">
              {new Intl.NumberFormat('fr-TN', { maximumFractionDigits: 0 }).format(monthlyPayment)}
            </span>
            <span className="text-xs font-semibold text-[#3D5166]">TND / mois</span>
          </div>

          {/* Detail toggle */}
          <div className="mt-3 pt-2.5 border-t border-[#FECACA]/60 flex items-center justify-between text-[11px] text-[#3D5166]">
            <span>Total remboursé: <strong className="text-[#0C1825]">{formatCurrency(totalRepaid)}</strong></span>
            <button
              type="button"
              onClick={() => setShowDetails(!showDetails)}
              className="text-[#C0272D] font-medium hover:underline text-[10px] inline-flex items-center gap-0.5"
            >
              {showDetails ? 'Masquer' : 'Détails'}
            </button>
          </div>

          {showDetails && (
            <div className="mt-2 text-left text-[10px] bg-white/70 p-2 rounded border border-[#FECACA]/60 space-y-1 text-[#3D5166]">
              <div className="flex justify-between">
                <span>Montant emprunté (Capital) :</span>
                <span className="font-semibold text-[#0C1825]">{formatCurrency(amount)}</span>
              </div>
              <div className="flex justify-between">
                <span>Coût total des intérêts :</span>
                <span className="font-semibold text-[#0C1825]">{formatCurrency(totalInterest)}</span>
              </div>
              <div className="flex justify-between">
                <span>Nombre d&apos;échéances :</span>
                <span className="font-semibold text-[#0C1825]">{durationMonths} mensualités</span>
              </div>
            </div>
          )}
        </div>

        {/* 5. CTA Button */}
        <Link
          href={`/register?amount=${amount}&duration=${durationMonths}&type=${loanType}`}
          className="w-full btn-red py-2.5 text-xs flex items-center justify-center gap-2 group shadow-sm"
        >
          <span>Demander ce financement</span>
          <ArrowRight className="size-3.5 group-hover:translate-x-1 transition-transform" />
        </Link>
      </div>
    </div>
  );
}
