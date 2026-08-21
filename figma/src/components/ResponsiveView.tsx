import { useState } from "react";
import { LandingView } from "./LandingView";
import { AuthView } from "./AuthView";
import { DashboardView } from "./DashboardView";
import { CreditApplicationView } from "./CreditApplicationView";

type DevicePreset = "1440" | "1280" | "1024" | "390" | "375";
type ScreenChoice = "landing" | "auth" | "dashboard" | "application";

export function ResponsiveView() {
  const [device, setDevice] = useState<DevicePreset>("390");
  const [screen, setScreen] = useState<ScreenChoice>("landing");

  const widthMap: Record<DevicePreset, string> = {
    "1440": "1440px",
    "1280": "1280px",
    "1024": "1024px",
    "390": "390px",
    "375": "375px",
  };

  return (
    <div className="space-y-6 pb-20">
      {/* ── Device & Screen Controls ── */}
      <div className="figma-card p-4 bg-white border-l-4 border-l-[var(--color-bts-red)] flex flex-wrap items-center justify-between gap-4">
        <div className="flex items-center gap-2">
          <span className="text-xs font-bold uppercase text-[var(--color-navy)]">Écran à tester :</span>
          {(
            [
              ["landing", "Landing Page"],
              ["auth", "Authentification"],
              ["dashboard", "Dashboard Client"],
              ["application", "Wizard Crédit"],
            ] as [ScreenChoice, string][]
          ).map(([s, label]) => (
            <button
              key={s}
              onClick={() => setScreen(s)}
              className={`px-3 py-1.5 rounded text-xs font-semibold transition-colors ${
                screen === s
                  ? "bg-[var(--color-bts-red)] text-white"
                  : "bg-[var(--color-mist)] text-[var(--color-steel)] hover:bg-gray-200"
              }`}
            >
              {label}
            </button>
          ))}
        </div>

        <div className="flex items-center gap-2">
          <span className="text-xs font-bold uppercase text-[var(--color-navy)]">Viewport :</span>
          {(
            [
              ["1440", "Desktop (1440px)"],
              ["1280", "Laptop (1280px)"],
              ["1024", "Tablet (1024px)"],
              ["390", "Mobile iPhone (390px)"],
              ["375", "Mobile Small (375px)"],
            ] as [DevicePreset, string][]
          ).map(([d, label]) => (
            <button
              key={d}
              onClick={() => setDevice(d)}
              className={`px-2.5 py-1 rounded text-[11px] font-medium transition-colors ${
                device === d
                  ? "bg-[var(--color-navy)] text-white"
                  : "bg-[var(--color-mist)] text-[var(--color-steel)] hover:bg-gray-200"
              }`}
            >
              {label}
            </button>
          ))}
        </div>
      </div>

      {/* ── Device Simulator Frame ── */}
      <div className="flex justify-center p-4 bg-gray-100/80 rounded-xl overflow-x-auto min-h-[700px]">
        <div
          style={{ width: widthMap[device], maxWidth: "100%" }}
          className={`bg-white shadow-2xl transition-all duration-300 overflow-hidden ${
            device === "390" || device === "375"
              ? "rounded-[36px] border-[10px] border-gray-900 ring-1 ring-gray-700/50"
              : "rounded-lg border border-gray-300"
          }`}
        >
          {/* Mobile notch / top bar simulation */}
          {(device === "390" || device === "375") && (
            <div className="bg-gray-900 text-white text-[10px] px-6 py-1 flex justify-between items-center select-none">
              <span>9:41</span>
              <div className="w-16 h-3 bg-black rounded-full"></div>
              <span>5G 100%</span>
            </div>
          )}

          {/* Screen render */}
          <div className="overflow-y-auto max-h-[800px]">
            {screen === "landing" && <LandingView />}
            {screen === "auth" && <div className="p-4"><AuthView /></div>}
            {screen === "dashboard" && <div className="p-4"><DashboardView /></div>}
            {screen === "application" && <div className="p-4"><CreditApplicationView /></div>}
          </div>
        </div>
      </div>
    </div>
  );
}
