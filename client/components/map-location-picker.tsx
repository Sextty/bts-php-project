'use client';

import { useEffect, useRef, useState } from 'react';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { Loader2, MapPin, Search } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';

interface PlaceResult {
  label: string;
  lat: number;
  lon: number;
}

async function searchPlaces(query: string): Promise<PlaceResult[]> {
  const res = await fetch(`https://photon.komoot.io/api/?q=${encodeURIComponent(query)}&limit=6&lang=fr`);
  if (!res.ok) throw new Error('Recherche indisponible, réessayez.');
  const data = (await res.json()) as {
    features?: Array<{
      properties?: { label?: string; name?: string };
      geometry?: { coordinates?: [number, number] };
    }>;
  };
  return (data.features ?? [])
    .map((feature) => ({
      label: feature.properties?.label ?? feature.properties?.name ?? '',
      lon: feature.geometry?.coordinates?.[0] ?? 0,
      lat: feature.geometry?.coordinates?.[1] ?? 0,
    }))
    .filter((place) => place.label);
}

async function reverseGeocode(lat: number, lon: number): Promise<string | null> {
  const res = await fetch(`https://photon.komoot.io/reverse?lat=${lat}&lon=${lon}&lang=fr`);
  if (!res.ok) return null;
  const data = (await res.json()) as {
    features?: Array<{ properties?: { label?: string; name?: string } }>;
  };
  return data.features?.[0]?.properties?.label ?? data.features?.[0]?.properties?.name ?? null;
}

export function MapLocationPicker({
  open,
  onOpenChange,
  onSelect,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onSelect: (address: string, lat?: number, lon?: number) => void;
}) {
  const [query, setQuery] = useState('');
  const [results, setResults] = useState<PlaceResult[] | null>(null);
  const [searching, setSearching] = useState(false);
  const [reversing, setReversing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const mapContainerRef = useRef<HTMLDivElement>(null);
  const mapRef = useRef<L.Map | null>(null);
  const markerRef = useRef<L.Marker | null>(null);

  useEffect(() => {
    if (!open) return;
    Promise.resolve().then(() => setError(null));
    if (mapContainerRef.current && !mapRef.current) {
      const map = L.map(mapContainerRef.current, { center: [33.8869, 9.5375], zoom: 6 });
      L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
      }).addTo(map);
      map.on('click', async (event) => {
        const { lat, lng } = event.latlng;
        setReversing(true);
        const label = await reverseGeocode(lat, lng);
        setReversing(false);
        if (label) {
          setMarker(lat, lng);
          onSelect(label, lat, lng);
          onOpenChange(false);
        }
      });
      mapRef.current = map;
      map.whenReady(() => map.invalidateSize());
      setTimeout(() => map.invalidateSize(), 300);
      setTimeout(() => map.invalidateSize(), 600);
    }
    return () => {
      mapRef.current?.remove();
      mapRef.current = null;
      markerRef.current = null;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  useEffect(() => {
    if (!open || query.trim().length < 3) return;
    const timer = setTimeout(async () => {
      setSearching(true);
      try {
        setResults(await searchPlaces(query.trim()));
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Erreur de recherche.');
        setResults(null);
      } finally {
        setSearching(false);
      }
    }, 350);
    return () => clearTimeout(timer);
  }, [open, query]);

  function setMarker(lat: number, lon: number) {
    const map = mapRef.current;
    if (!map) return;
    markerRef.current?.remove();
    const icon = L.divIcon({
      className: '',
      html: '<div style="width:16px;height:16px;border-radius:50%;background:#C21E40;border:2px solid white;box-shadow:0 1px 4px rgba(0,0,0,.4);"></div>',
      iconSize: [16, 16],
      iconAnchor: [8, 8],
    });
    markerRef.current = L.marker([lat, lon], { icon }).addTo(map);
  }

  function handlePick(place: PlaceResult) {
    setMarker(place.lat, place.lon);
    mapRef.current?.setView([place.lat, place.lon], 15);
    onSelect(place.label, place.lat, place.lon);
    onOpenChange(false);
  }

  const visibleResults = query.trim().length >= 3 ? results : null;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Importer la localisation</DialogTitle>
          <DialogDescription>
            Recherchez une adresse (ex : Tunis, Sfax…) ou cliquez directement sur la carte.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-3">
          <div className="relative">
            <Search className="absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
            <label htmlFor="location-search" className="sr-only">
              Rechercher une adresse
            </label>
            <Input
              id="location-search"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder="Saisir une adresse…"
              className="pl-8"
            />
            {searching && (
              <Loader2 className="absolute right-2.5 top-1/2 size-4 -translate-y-1/2 animate-spin text-muted-foreground" aria-hidden />
            )}
          </div>

          {visibleResults && visibleResults.length > 0 && (
            <ul className="max-h-40 overflow-auto rounded-lg border border-border/60">
              {visibleResults.map((place) => (
                <li key={`${place.lat}-${place.lon}-${place.label}`}>
                  <button
                    type="button"
                    onClick={() => handlePick(place)}
                    className="flex w-full items-start gap-2 px-3 py-2 text-left text-sm hover:bg-accent/40 focus-visible:bg-accent/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                  >
                    <MapPin className="mt-0.5 size-4 shrink-0 text-primary" aria-hidden />
                    <span>{place.label}</span>
                  </button>
                </li>
              ))}
            </ul>
          )}

          {error && <p className="text-sm text-destructive">{error}</p>}

          <div ref={mapContainerRef} className="h-64 w-full rounded-lg border border-border bg-muted/30" />
          {reversing && <p className="text-sm text-muted-foreground">Recherche de l&apos;adresse…</p>}

          <Button type="button" variant="outline" className="w-full" onClick={() => onOpenChange(false)}>
            Fermer
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  );
}