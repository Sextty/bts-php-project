'use client';

import { useId, useMemo, useState } from 'react';
import { cn } from '@/lib/utils';

export interface TrendSeries {
  key: string;
  label: string;
  /** CSS custom property name from the validated viz palette, e.g. "--viz-series-1". */
  colorVar: string;
  values: number[];
}

/**
 * Multi-series line chart over a date axis, drawn as inline SVG.
 *
 * Hand-rolled rather than pulling in a charting library: the whole need is three polylines and a
 * crosshair, and the smallest React chart packages cost more transferred JavaScript than this
 * entire dashboard. It also means the marks read directly from the theme's CSS custom properties,
 * so light/dark switches without a re-render.
 *
 * Identity is never carried by colour alone — a legend is always rendered, and each line is
 * labelled at its endpoint.
 */
export function TrendChart({
  labels,
  series,
  height = 220,
  valueSuffix = '',
}: {
  labels: string[];
  series: TrendSeries[];
  height?: number;
  valueSuffix?: string;
}) {
  const clipId = useId();
  const [hover, setHover] = useState<number | null>(null);

  // viewBox units — the SVG scales to its container, so these are layout units, not pixels.
  const W = 720;
  const H = height;
  const pad = { top: 16, right: 56, bottom: 26, left: 36 };
  const plotW = W - pad.left - pad.right;
  const plotH = H - pad.top - pad.bottom;

  const max = useMemo(() => {
    const peak = Math.max(1, ...series.flatMap((s) => s.values));
    // Round up to a clean tick so the y-axis reads 0 / 4 / 8 rather than 0 / 3.5 / 7.
    const step = Math.pow(10, Math.floor(Math.log10(peak)));
    return Math.ceil(peak / step) * step;
  }, [series]);

  const n = labels.length;
  const x = (i: number) => pad.left + (n <= 1 ? plotW / 2 : (i / (n - 1)) * plotW);
  const y = (v: number) => pad.top + plotH - (v / max) * plotH;

  const ticks = [0, max / 2, max];

  /**
   * End-label placement. Series routinely converge — a quiet day leaves all of them at zero, which
   * would stack every label on the same pixel. So labels are laid out top-down with a minimum gap
   * and pushed apart where needed; the marker stays on the true value and a leader line bridges the
   * two, keeping each label attached to its own line.
   */
  const endLabels = useMemo(() => {
    const MIN_GAP = 12;
    const top = pad.top;
    const bottom = pad.top + plotH;

    const rows = series
      .map((s) => {
        const value = s.values[s.values.length - 1] ?? 0;
        return { s, value, markerY: y(value) };
      })
      .sort((a, b) => a.markerY - b.markerY);

    // Push down to separate…
    const ys: number[] = [];
    rows.forEach((row, i) => {
      ys[i] = i === 0 ? row.markerY : Math.max(row.markerY, ys[i - 1] + MIN_GAP);
    });

    // …then pull back up if that ran past the plot floor, so labels never spill onto the date
    // axis or outside the viewBox. With every series at the same value this is what keeps three
    // stacked labels inside the chart instead of below it.
    if (ys[ys.length - 1] > bottom) {
      ys[ys.length - 1] = bottom;
      for (let i = ys.length - 2; i >= 0; i--) {
        ys[i] = Math.min(ys[i], ys[i + 1] - MIN_GAP);
      }
    }

    return rows.map((row, i) => ({ ...row, labelY: Math.max(top, ys[i]) }));
    // y() and the padding are derived from `max`/`height`, both already tracked here.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [series, max, plotH]);

  return (
    <div className="w-full">
      {/* Legend — always present for two or more series. */}
      <div className="mb-3 flex flex-wrap items-center gap-x-4 gap-y-1.5">
        {series.map((s) => (
          <span key={s.key} className="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
            <span
              aria-hidden
              className="inline-block h-0.5 w-3.5 rounded-full"
              style={{ backgroundColor: `var(${s.colorVar})` }}
            />
            {s.label}
          </span>
        ))}
      </div>

      <svg
        viewBox={`0 0 ${W} ${H}`}
        className="w-full"
        style={{ height }}
        role="img"
        aria-label={`${n}-day trend: ${series.map((s) => s.label).join(', ')}`}
        onMouseLeave={() => setHover(null)}
      >
        <defs>
          <clipPath id={clipId}>
            <rect x={pad.left} y={pad.top} width={plotW} height={plotH} />
          </clipPath>
        </defs>

        {/* Gridlines: hairline, solid, recessive. */}
        {ticks.map((t) => (
          <g key={t}>
            <line
              x1={pad.left}
              x2={pad.left + plotW}
              y1={y(t)}
              y2={y(t)}
              stroke="var(--viz-grid)"
              strokeWidth={1}
            />
            <text
              x={pad.left - 8}
              y={y(t) + 3}
              textAnchor="end"
              className="fill-muted-foreground"
              style={{ fontSize: 10, fontVariantNumeric: 'tabular-nums' }}
            >
              {Math.round(t)}
            </text>
          </g>
        ))}

        {/* Crosshair for the hovered day, drawn under the marks. */}
        {hover !== null && (
          <line
            x1={x(hover)}
            x2={x(hover)}
            y1={pad.top}
            y2={pad.top + plotH}
            stroke="var(--viz-axis)"
            strokeWidth={1}
          />
        )}

        <g clipPath={`url(#${clipId})`}>
          {series.map((s) => (
            <polyline
              key={s.key}
              points={s.values.map((v, i) => `${x(i)},${y(v)}`).join(' ')}
              fill="none"
              stroke={`var(${s.colorVar})`}
              strokeWidth={2}
              strokeLinejoin="round"
              strokeLinecap="round"
            />
          ))}
        </g>

        {/* End markers + direct labels — the "relief" that keeps identity off colour alone. */}
        {endLabels.map(({ s, value, markerY, labelY }) => (
          <g key={`${s.key}-end`}>
            {/* When series converge, labels are pushed apart and a leader line keeps each one
                attached to its own line — stacking them unanchored would read as noise. */}
            {Math.abs(labelY - markerY) > 0.5 && (
              <polyline
                points={`${x(n - 1) + 5},${markerY} ${x(n - 1) + 7},${labelY} ${x(n - 1) + 8},${labelY}`}
                fill="none"
                stroke="var(--viz-axis)"
                strokeWidth={1}
              />
            )}
            <circle
              cx={x(n - 1)}
              cy={markerY}
              r={4}
              fill={`var(${s.colorVar})`}
              stroke="var(--color-card)"
              strokeWidth={2}
            />
            <text
              x={x(n - 1) + 10}
              y={labelY + 3}
              className="fill-foreground"
              style={{ fontSize: 10, fontWeight: 500 }}
            >
              {value}
              {valueSuffix}
            </text>
          </g>
        ))}

        {/* First and last date, so the axis has anchors without crowding. */}
        <text x={pad.left} y={H - 8} className="fill-muted-foreground" style={{ fontSize: 10 }}>
          {labels[0]}
        </text>
        <text
          x={pad.left + plotW}
          y={H - 8}
          textAnchor="end"
          className="fill-muted-foreground"
          style={{ fontSize: 10 }}
        >
          {labels[n - 1]}
        </text>

        {/* Invisible hit bands — wider than the marks, so hovering is forgiving. */}
        {labels.map((_, i) => (
          <rect
            key={i}
            x={x(i) - plotW / Math.max(n - 1, 1) / 2}
            y={pad.top}
            width={plotW / Math.max(n - 1, 1)}
            height={plotH}
            fill="transparent"
            onMouseEnter={() => setHover(i)}
          />
        ))}
      </svg>

      {/* Tooltip as DOM rather than SVG text: it wraps, inherits type styles, and can't be clipped. */}
      <div className="mt-2 min-h-9 text-xs">
        {hover !== null ? (
          <div className="inline-flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md border border-border/60 bg-card px-2.5 py-1.5">
            <span className="font-medium">{labels[hover]}</span>
            {series.map((s) => (
              <span key={s.key} className="inline-flex items-center gap-1.5 text-muted-foreground">
                <span
                  aria-hidden
                  className="inline-block size-2 rounded-full"
                  style={{ backgroundColor: `var(${s.colorVar})` }}
                />
                {s.label} <span className="font-medium text-foreground">{s.values[hover]}</span>
              </span>
            ))}
          </div>
        ) : (
          <span className="text-muted-foreground">Hover the chart for a day&apos;s detail.</span>
        )}
      </div>
    </div>
  );
}

/** Horizontal bars for ranked magnitudes (pipeline, top actions). Length carries the value. */
export function BarList({
  items,
  emptyLabel = 'No data yet.',
  className,
}: {
  items: { label: string; value: number; hint?: string }[];
  emptyLabel?: string;
  className?: string;
}) {
  const max = Math.max(1, ...items.map((i) => i.value));

  if (items.length === 0) {
    return <p className="py-6 text-center text-sm text-muted-foreground">{emptyLabel}</p>;
  }

  return (
    <ul className={cn('space-y-2.5', className)}>
      {items.map((item) => (
        <li key={item.label} className="space-y-1">
          <div className="flex items-baseline justify-between gap-3 text-sm">
            <span className="truncate" title={item.hint ?? item.label}>
              {item.label}
            </span>
            <span className="shrink-0 font-medium tabular-nums">{item.value}</span>
          </div>
          {/* Track is a lighter step of the same hue so the bar reads across the full width. */}
          <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
            <div
              className="h-full rounded-full"
              style={{
                width: `${Math.max((item.value / max) * 100, 2)}%`,
                backgroundColor: 'var(--viz-series-1)',
              }}
            />
          </div>
        </li>
      ))}
    </ul>
  );
}
