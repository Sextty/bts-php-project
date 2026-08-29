import { Skeleton } from '@/components/ui/skeleton';

export function DashboardSkeleton() {
  return (
    <div className="min-h-screen bg-[#F4F6F8]">
      {/* Header Skeleton */}
      <div className="border-b border-[#E0E4E9] bg-white px-4 py-3.5 sm:px-8">
        <div className="mx-auto max-w-7xl flex items-center justify-between">
          <div className="flex items-center gap-3">
            <Skeleton className="size-9 rounded-md" />
            <Skeleton className="h-6 w-32" />
          </div>
          <div className="flex items-center gap-3">
            <Skeleton className="size-8 rounded-full" />
            <Skeleton className="h-8 w-24 rounded-md" />
          </div>
        </div>
      </div>

      {/* Main Content Skeleton */}
      <main id="main" className="mx-auto max-w-7xl px-4 sm:px-8 py-8 space-y-8">
        {/* Welcome Section */}
        <div className="space-y-2">
          <Skeleton className="h-4 w-36" />
          <Skeleton className="h-8 w-64" />
          <Skeleton className="h-4 w-80" />
        </div>

        {/* 3 Overview Cards */}
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          {Array.from({ length: 3 }).map((_, i) => (
            <div key={i} className="figma-card p-5 bg-white space-y-3">
              <div className="flex justify-between">
                <Skeleton className="h-3 w-28" />
                <Skeleton className="size-8 rounded-lg" />
              </div>
              <Skeleton className="h-8 w-36" />
              <Skeleton className="h-3 w-full" />
            </div>
          ))}
        </div>

        {/* Quick Actions Skeleton */}
        <div className="space-y-3">
          <Skeleton className="h-5 w-40" />
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            {Array.from({ length: 6 }).map((_, i) => (
              <div key={i} className="figma-card p-4 bg-white flex items-center gap-3">
                <Skeleton className="size-10 rounded-lg shrink-0" />
                <div className="space-y-1 flex-1">
                  <Skeleton className="h-4 w-28" />
                  <Skeleton className="h-3 w-36" />
                </div>
              </div>
            ))}
          </div>
        </div>

        {/* 2-Column Grid */}
        <div className="grid grid-cols-1 lg:grid-cols-5 gap-6">
          <div className="lg:col-span-3 space-y-6">
            <div className="figma-card p-6 bg-white space-y-4">
              <Skeleton className="h-5 w-48" />
              <Skeleton className="h-20 w-full rounded-xl" />
              <Skeleton className="h-20 w-full rounded-xl" />
            </div>
          </div>
          <div className="lg:col-span-2 space-y-6">
            <div className="figma-card p-6 bg-white space-y-4">
              <Skeleton className="h-5 w-36" />
              <Skeleton className="h-16 w-full rounded-xl" />
              <Skeleton className="h-16 w-full rounded-xl" />
            </div>
          </div>
        </div>
      </main>
    </div>
  );
}
