import { useState } from "react";
import btsLogo from "@/imports/BTS_Banque.png";
import { DesignSystemView } from "./components/DesignSystemView";
import { LandingView } from "./components/LandingView";
import { AuthView } from "./components/AuthView";
import { DashboardView } from "./components/DashboardView";
import { CreditApplicationView } from "./components/CreditApplicationView";
import { ResponsiveView } from "./components/ResponsiveView";

type MainView = "design_system" | "landing" | "auth" | "dashboard" | "application" | "responsive";

export default function App() {
  const [activeView, setActiveView] = useState<MainView>("design_system");

  return (
    <div className="min-h-screen flex flex-col bg-[#F8FAFC]">
      {/* ── Top Figma Workspace Navigation ── */}
      <header className="sticky top-0 z-50 bg-[#0C1825] text-white border-b border-gray-800 shadow-md">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between gap-4">
          {/* Brand & Project Tag */}
          <div className="flex items-center gap-3">
            <img src={btsLogo} alt="BTS" className="h-8 w-auto filter brightness-0 invert" />
            <div className="hidden sm:block">
              <span className="font-display font-medium text-sm text-white">BTS Bank</span>
              <span className="text-[10px] font-mono text-gray-400 block -mt-0.5">Figma Design Upgrade v2.0</span>
            </div>
          </div>

          {/* Navigation Pages / Modules */}
          <nav className="flex items-center gap-1 overflow-x-auto py-1">
            {[
              { id: "design_system", label: "🎨 Design System" },
              { id: "landing", label: "🌐 Landing" },
              { id: "auth", label: "🔐 Auth" },
              { id: "dashboard", label: "📊 Dashboard" },
              { id: "application", label: "📝 Wizard Crédit" },
              { id: "responsive", label: "📱 Responsive" },
            ].map((tab) => (
              <button
                key={tab.id}
                onClick={() => setActiveView(tab.id as MainView)}
                className={`px-3 py-1.5 rounded text-xs font-semibold whitespace-nowrap transition-all ${
                  activeView === tab.id
                    ? "bg-[var(--color-bts-red)] text-white shadow-sm"
                    : "text-gray-300 hover:text-white hover:bg-white/10"
                }`}
              >
                {tab.label}
              </button>
            ))}
          </nav>

          {/* Policy indicator badge */}
          <div className="hidden md:flex items-center gap-2">
            <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-emerald-950/80 border border-emerald-700/60 text-[10px] font-mono text-emerald-300 font-medium">
              <span className="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
              Zero Fake Data
            </span>
          </div>
        </div>
      </header>

      {/* ── Main View Container ── */}
      <main className="flex-1">
        {activeView === "landing" ? (
          <LandingView />
        ) : (
          <div className="max-w-7xl mx-auto px-4 sm:px-6 pt-8">
            {activeView === "design_system" && <DesignSystemView />}
            {activeView === "auth" && <AuthView />}
            {activeView === "dashboard" && <DashboardView />}
            {activeView === "application" && <CreditApplicationView />}
            {activeView === "responsive" && <ResponsiveView />}
          </div>
        )}
      </main>
    </div>
  );
}
