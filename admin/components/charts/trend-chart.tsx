'use client';

import { useId, useMemo, useState } from 'react';
import { cn } from '@/lib/utils';
import { TrendingUp, BarChart2, Activity } from 'lucide-react';

export interface TrendSeries {
  key: string;
  label: string;
  /** CSS custom property name from the validated viz palette, e.g. "--viz-series-1" or a hex color. */
  colorVar: string;
  values: number[];
}

type ChartMode = 'area' | 'line' | 'bar';

/**
 * Calculates Catmull-Rom smooth cubic Bézier SVG path through a series of points.
 */
function getSmoothPath(points: { x: number; y: number }[]): string {
  if (points.length === 0) return '';
  if (points.length === 1) return `M ${points[0].x},${points[0].y}`;
  if (points.length === 2) return `M ${points[0].x},${points[0].y} L ${points[1].x},${points[1].y}`;

  let d = `M ${points[0].x.toFixed(2)},${points[0].y.toFixed(2)}`;
  for (let i = 0; i < points.length - 1; i++) {
    const p0 = points[i === 0 ? 0 : i - 1];
    const p1 = points[i];
    const p2 = points[i + 1];
    const p3 = points[i + 2 >= points.length ? points.length - 1 : i + 2];

    const cp1x = p1.x + (p2.x - p0.x) / 6;
    const cp1y = p1.y + (p2.y - p0.y) / 6;
    const cp2x = p2.x - (p3.x - p1.x) / 6;
    const cp2y = p2.y - (p3.y - p1.y) / 6;

    d += ` C ${cp1x.toFixed(2)},${cp1y.toFixed(2)} ${cp2x.toFixed(2)},${cp2y.toFixed(2)} ${p2.x.toFixed(2)},${p2.y.toFixed(2)}`;
  }
  return d;
}

/**
 * Creates a closed area path underneath the smooth curve down to the baseline.
 */
function getAreaPath(points: { x: number; y: number }[], baselineY: number): string {
  if (points.length === 0) return '';
  const linePath = getSmoothPath(points);
  const first = points[0];
  const last = points[points.length - 1];
  return `${linePath} L ${last.x.toFixed(2)},${baselineY.toFixed(2)} L ${first.x.toFixed(2)},${baselineY.toFixed(2)} Z`;
}

export function TrendChart({
  labels,
  series,
  height = 240,
  valueSuffix = '',
  showControls = true,
}: {
  labels: string[];
  series: TrendSeries[];
  height?: number;
  valueSuffix?: string;
  showControls?: boolean;
}) {
  const clipId = useId();
  const gradPrefix = useId();
  const [hover, setHover] = useState<number | null>(null);
  const [chartMode, setChartMode] = useState<ChartMode>('area');

  // SVG viewBox units
  const W = 720;
  const H = height;
  const pad = { top: 20, right: 48, bottom: 28, left: 38 };
  const plotW = W - pad.left - pad.right;
  const plotH = H - pad.top - pad.bottom;

  // Max value calculation
  const max = useMemo(() => {
    const peak = Math.max(1, ...series.flatMap((s) => s.values));
    const step = Math.pow(10, Math.floor(Math.log10(peak)));
    const rounded = Math.ceil(peak / (step / 2)) * (step / 2);
    return Math.max(rounded, 4);
  }, [series]);

  const n = labels.length;
  const x = (i: number) => pad.left + (n <= 1 ? plotW / 2 : (i / (n - 1)) * plotW);
  const y = (v: number) => pad.top + plotH - (v / max) * plotH;
  const baselineY = pad.top + plotH;

  // Grid Ticks
  const ticks = useMemo(() => {
    return [0, max * 0.33, max * 0.66, max];
  }, [max]);

  // Intermediate X-axis date indices
  const dateTickIndices = useMemo(() => {
    if (n <= 5) return labels.map((_, i) => i);
    const step = Math.floor((n - 1) / 4);
    return [0, step, step * 2, step * 3, n - 1];
  }, [labels, n]);

  // Statistical summary of the primary series
  const stats = useMemo(() => {
    if (series.length === 0 || series[0].values.length === 0) {
      return { total: 0, avg: 0, peak: 0, peakIndex: 0 };
    }
    const primary = series[0].values;
    const total = primary.reduce((sum, v) => sum + v, 0);
    const avg = total / primary.length;
    let peak = 0;
    let peakIndex = 0;
    primary.forEach((v, i) => {
      if (v > peak) {
        peak = v;
        peakIndex = i;
      }
    });
    return { total, avg, peak, peakIndex };
  }, [series]);

  // Precomputed points for each series
  const seriesPoints = useMemo(() => {
    return series.map((s) => {
      const pts = s.values.map((v, i) => ({ x: x(i), y: y(v) }));
      return {
        ...s,
        points: pts,
        smoothPath: getSmoothPath(pts),
        areaPath: getAreaPath(pts, baselineY),
      };
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [series, n, max, plotW, plotH]);

  const getColor = (colorVar: string) => {
    return colorVar.startsWith('--') ? `var(${colorVar})` : colorVar;
  };

  return (
    <div className="w-full space-y-3">
      {/* ── Chart Header Toolbar & Stats ── */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-[#E0E4E9]/80 pb-2.5">
        {/* Series Legends */}
        <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5">
          {series.map((s) => (
            <span key={s.key} className="inline-flex items-center gap-1.5 text-xs font-semibold text-[#0C1825]">
              <span
                aria-hidden
                className="inline-block size-2.5 rounded-full ring-2 ring-white shadow-xs"
                style={{ backgroundColor: getColor(s.colorVar) }}
              />
              <span>{s.label}</span>
              {series.length === 1 && (
                <span className="font-mono text-[11px] text-[#3D5166]">
                  (Moyenne : <strong>{stats.avg.toFixed(1)}</strong>/j)
                </span>
              )}
            </span>
          ))}
        </div>

        {/* View Mode Toggle Controls */}
        {showControls && (
          <div className="flex items-center gap-1 bg-[#F4F6F8] p-0.5 rounded-lg border border-[#E0E4E9] self-start sm:self-auto">
            <button
              type="button"
              onClick={() => setChartMode('area')}
              className={cn(
                'inline-flex items-center gap-1 px-2.5 py-1 text-[11px] font-semibold rounded-md transition-all',
                chartMode === 'area'
                  ? 'bg-white text-[#C0272D] shadow-xs'
                  : 'text-[#3D5166] hover:text-[#0C1825]'
              )}
              title="Vue Courbe & Surface"
            >
              <TrendingUp className="size-3" />
              <span>Aire</span>
            </button>
            <button
              type="button"
              onClick={() => setChartMode('line')}
              className={cn(
                'inline-flex items-center gap-1 px-2.5 py-1 text-[11px] font-semibold rounded-md transition-all',
                chartMode === 'line'
                  ? 'bg-white text-[#C0272D] shadow-xs'
                  : 'text-[#3D5166] hover:text-[#0C1825]'
              )}
              title="Vue Ligne Simple"
            >
              <Activity className="size-3" />
              <span>Ligne</span>
            </button>
            <button
              type="button"
              onClick={() => setChartMode('bar')}
              className={cn(
                'inline-flex items-center gap-1 px-2.5 py-1 text-[11px] font-semibold rounded-md transition-all',
                chartMode === 'bar'
                  ? 'bg-white text-[#C0272D] shadow-xs'
                  : 'text-[#3D5166] hover:text-[#0C1825]'
              )}
              title="Vue Histogramme"
            >
              <BarChart2 className="size-3" />
              <span>Barres</span>
            </button>
          </div>
        )}
      </div>

      {/* ── Interactive SVG Canvas ── */}
      <div className="relative">
        <svg
          viewBox={`0 0 ${W} ${H}`}
          className="w-full overflow-visible select-none"
          style={{ height }}
          role="img"
          aria-label={`${n}-day trend: ${series.map((s) => s.label).join(', ')}`}
          onMouseLeave={() => setHover(null)}
        >
          <defs>
            <clipPath id={clipId}>
              <rect x={pad.left} y={pad.top - 4} width={plotW} height={plotH + 8} />
            </clipPath>

            {/* Gradient definition for each series */}
            {series.map((s) => (
              <linearGradient
                key={s.key}
                id={`${gradPrefix}-${s.key}`}
                x1="0"
                y1="0"
                x2="0"
                y2="1"
              >
                <stop offset="0%" stopColor={getColor(s.colorVar)} stopOpacity={0.35} />
                <stop offset="50%" stopColor={getColor(s.colorVar)} stopOpacity={0.12} />
                <stop offset="100%" stopColor={getColor(s.colorVar)} stopOpacity={0.0} />
              </linearGradient>
            ))}
          </defs>

          {/* 1. Horizontal Gridlines & Y-Axis Numeric Labels */}
          {ticks.map((t, idx) => {
            const currentY = y(t);
            return (
              <g key={`tick-${idx}`}>
                <line
                  x1={pad.left}
                  x2={pad.left + plotW}
                  y1={currentY}
                  y2={currentY}
                  stroke="#E0E4E9"
                  strokeWidth={1}
                  strokeDasharray={t === 0 ? 'none' : '3 3'}
                  opacity={0.8}
                />
                <text
                  x={pad.left - 8}
                  y={currentY + 3.5}
                  textAnchor="end"
                  fill="#6B7280"
                  style={{ fontSize: 10, fontVariantNumeric: 'tabular-nums', fontWeight: 500 }}
                >
                  {Math.round(t)}
                </text>
              </g>
            );
          })}

          {/* 2. Hover Crosshair Column Highlight */}
          {hover !== null && (
            <g>
              <rect
                x={x(hover) - plotW / Math.max(n - 1, 1) / 2}
                y={pad.top}
                width={plotW / Math.max(n - 1, 1)}
                height={plotH}
                fill="#C0272D"
                opacity={0.04}
                rx={4}
              />
              <line
                x1={x(hover)}
                x2={x(hover)}
                y1={pad.top}
                y2={pad.top + plotH}
                stroke="#C0272D"
                strokeWidth={1.5}
                strokeDasharray="3 3"
                opacity={0.6}
              />
            </g>
          )}

          {/* 3. Render Area & Lines OR Bar Chart */}
          <g clipPath={`url(#${clipId})`}>
            {chartMode === 'bar' ? (
              <g>
                {series.map((s, sIdx) => {
                  const barWidth = Math.max(4, (plotW / Math.max(n, 1)) * 0.55 / series.length);
                  return s.values.map((v, i) => {
                    const barH = ((v / max) * plotH);
                    const barY = pad.top + plotH - barH;
                    const barX = x(i) - (barWidth * series.length) / 2 + sIdx * barWidth;
                    const isHovered = hover === i;
                    return (
                      <rect
                        key={`${s.key}-bar-${i}`}
                        x={barX}
                        y={barY}
                        width={barWidth}
                        height={Math.max(barH, 2)}
                        rx={2.5}
                        fill={getColor(s.colorVar)}
                        opacity={isHovered ? 1 : 0.82}
                        className="transition-all duration-150"
                      />
                    );
                  });
                })}
              </g>
            ) : (
              <g>
                {seriesPoints.map((s) => (
                  <g key={`series-render-${s.key}`}>
                    {chartMode === 'area' && (
                      <path
                        d={s.areaPath}
                        fill={`url(#${gradPrefix}-${s.key})`}
                      />
                    )}

                    <path
                      d={s.smoothPath}
                      fill="none"
                      stroke={getColor(s.colorVar)}
                      strokeWidth={2.5}
                      strokeLinecap="round"
                      strokeLinejoin="round"
                    />

                    {n <= 30 && s.points.map((pt, pIdx) => {
                      const isHovered = hover === pIdx;
                      const isPeak = pIdx === stats.peakIndex && stats.peak > 0;
                      return (
                        <circle
                          key={`pt-${pIdx}`}
                          cx={pt.x}
                          cy={pt.y}
                          r={isHovered ? 5 : isPeak ? 3.5 : 2}
                          fill={isHovered ? '#FFFFFF' : getColor(s.colorVar)}
                          stroke={isHovered ? getColor(s.colorVar) : '#FFFFFF'}
                          strokeWidth={isHovered ? 2.5 : 1}
                          className="transition-all duration-100"
                        />
                      );
                    })}
                  </g>
                ))}
              </g>
            )}
          </g>

          {/* 4. Active Hovered Point Marker */}
          {hover !== null && (
            <g>
              {seriesPoints.map((s) => {
                const pt = s.points[hover];
                if (!pt) return null;
                return (
                  <g key={`hover-pt-${s.key}`}>
                    <circle
                      cx={pt.x}
                      cy={pt.y}
                      r={9}
                      fill={getColor(s.colorVar)}
                      opacity={0.2}
                    />
                    <circle
                      cx={pt.x}
                      cy={pt.y}
                      r={4.5}
                      fill={getColor(s.colorVar)}
                      stroke="#FFFFFF"
                      strokeWidth={2}
                    />
                  </g>
                );
              })}
            </g>
          )}

          {/* 5. X-Axis Date Anchors */}
          {dateTickIndices.map((idx) => {
            if (idx >= n) return null;
            return (
              <text
                key={`date-tick-${idx}`}
                x={x(idx)}
                y={H - 6}
                textAnchor={idx === 0 ? 'start' : idx === n - 1 ? 'end' : 'middle'}
                fill="#6B7280"
                style={{ fontSize: 10, fontWeight: 500 }}
              >
                {labels[idx]}
              </text>
            );
          })}

          {/* 6. Transparent Interactive Hover Catchers */}
          {labels.map((_, i) => (
            <rect
              key={`catcher-${i}`}
              x={x(i) - plotW / Math.max(n - 1, 1) / 2}
              y={pad.top}
              width={plotW / Math.max(n - 1, 1)}
              height={plotH}
              fill="transparent"
              onMouseEnter={() => setHover(i)}
            />
          ))}
        </svg>
      </div>

      {/* ── 3. Interactive Tooltip & Context Box ── */}
      <div className="min-h-[44px] flex items-center justify-between p-2.5 bg-[#F4F6F8] rounded-xl border border-[#E0E4E9] text-xs">
        {hover !== null ? (
          <div className="flex flex-wrap items-center justify-between w-full gap-2">
            <div className="flex items-center gap-2">
              <span className="font-bold text-[#0C1825] bg-white px-2 py-0.5 rounded border border-[#E0E4E9]">
                📅 {labels[hover]}
              </span>
              {series.map((s) => {
                const val = s.values[hover] ?? 0;
                const isAboveAvg = val >= stats.avg;
                return (
                  <div key={s.key} className="flex items-center gap-1.5">
                    <span
                      aria-hidden
                      className="inline-block size-2 rounded-full"
                      style={{ backgroundColor: getColor(s.colorVar) }}
                    />
                    <span className="text-[#3D5166]">{s.label} :</span>
                    <span className="font-bold font-mono text-sm text-[#0C1825]">
                      {val} {valueSuffix}
                    </span>
                    {series.length === 1 && stats.avg > 0 && (
                      <span className={cn(
                        'text-[10px] font-semibold px-1.5 py-0.2 rounded',
                        isAboveAvg ? 'bg-emerald-100 text-emerald-800' : 'bg-gray-200 text-gray-700'
                      )}>
                        {val >= stats.avg ? `+${Math.round(((val - stats.avg) / stats.avg) * 100)}%` : `-${Math.round(((stats.avg - val) / stats.avg) * 100)}%`} vs moy.
                      </span>
                    )}
                  </div>
                );
              })}
            </div>
            <span className="text-[11px] text-[#3D5166] italic hidden sm:inline">
              Survolez les autres dates pour comparer
            </span>
          </div>
        ) : (
          <div className="flex items-center justify-between w-full text-xs text-[#3D5166]">
            <span className="inline-flex items-center gap-1.5">
              <Activity className="size-3.5 text-[#C0272D]" />
              <span>Survolez la courbe pour explorer le détail quotidien.</span>
            </span>
            {stats.peak > 0 && (
              <span className="text-[11px] font-semibold text-[#0C1825]">
                Pic d&apos;activité : <strong className="text-[#C0272D]">{stats.peak}</strong> le {labels[stats.peakIndex]}
              </span>
            )}
          </div>
        )}
      </div>
    </div>
  );
}

/** Horizontal bars for ranked magnitudes (pipeline, top actions). Length carries the value. */
export function BarList({
  items,
  emptyLabel = 'Aucune donnée enregistrée.',
  className,
}: {
  items: { label: string; value: number; hint?: string }[];
  emptyLabel?: string;
  className?: string;
}) {
  const max = Math.max(1, ...items.map((i) => i.value));
  const total = items.reduce((sum, i) => sum + i.value, 0);

  if (items.length === 0) {
    return <p className="py-6 text-center text-sm text-[#3D5166]">{emptyLabel}</p>;
  }

  return (
    <ul className={cn('space-y-3', className)}>
      {items.map((item, idx) => {
        const pct = Math.round((item.value / max) * 100);
        const shareOfTotal = total > 0 ? Math.round((item.value / total) * 100) : 0;
        return (
          <li key={`${item.label}-${idx}`} className="space-y-1.5">
            <div className="flex items-baseline justify-between gap-3 text-xs">
              <span className="truncate font-medium text-[#0C1825]" title={item.hint ?? item.label}>
                {item.label}
              </span>
              <div className="flex items-center gap-1.5 shrink-0">
                <span className="font-bold font-mono text-[#0C1825]">{item.value}</span>
                <span className="text-[10px] text-[#3D5166] bg-[#F4F6F8] px-1 rounded border border-[#E0E4E9]">
                  {shareOfTotal}%
                </span>
              </div>
            </div>
            <div className="h-2 w-full overflow-hidden rounded-full bg-[#F4F6F8] border border-[#E0E4E9]/50">
              <div
                className="h-full rounded-full bg-gradient-to-r from-[#C0272D] to-[#E05258] transition-all duration-300"
                style={{ width: `${Math.max(pct, 2)}%` }}
              />
            </div>
          </li>
        );
      })}
    </ul>
  );
}
