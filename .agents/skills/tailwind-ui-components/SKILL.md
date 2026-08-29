---
name: tailwind-ui-components
description: >
  Generate, style, and refactor modern, accessible Tailwind CSS UI components (forms, tables, cards, modals, navbars, badges, dashboards) for PHP, HTML, and web views.
  Use when the user asks for Tailwind components, UI design, modern HTML styling, or frontend interface elements.
---

# Tailwind UI Components Skill

Build production-grade Tailwind CSS components with clean semantics, accessible markup, dark/light mode support, and responsive layouts.

## Guidelines

1. **Utility First**: Use standard Tailwind CSS utility classes. Avoid inline `style=""` attributes.
2. **Responsive by Default**: Mobile-first design using `sm:`, `md:`, `lg:`, and `xl:` breakpoints.
3. **Accessibility (a11y)**:
   - Proper `aria-*` attributes (`aria-label`, `aria-expanded`, `aria-hidden`).
   - Focus rings on interactive elements (`focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500`).
   - Contrast compliant text colors (e.g. `text-slate-900` / `text-slate-600` on light, `text-white` / `text-slate-300` on dark).
4. **State Transitions**: Smooth hover, active, and focus transitions (`transition ease-in-out duration-150`).
5. **PHP / HTML Integration**:
   - Keep dynamic PHP echoes clean: `class="px-4 py-2 <?= $isActive ? 'bg-indigo-600 text-white' : 'text-slate-700 hover:bg-slate-100' ?>"`.
   - Use reusable partials or includes for common components.

## Core Component Patterns

### 1. Modern Card & Container
```html
<div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 p-6">
  <div class="flex items-center justify-between pb-4 border-b border-slate-100 dark:border-slate-700/60">
    <h3 class="text-base font-semibold text-slate-900 dark:text-white">Title</h3>
    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-400">Active</span>
  </div>
  <div class="mt-4">
    <!-- Card Body -->
  </div>
</div>
```

### 2. Data Table
```html
<div class="overflow-x-auto rounded-lg border border-slate-200 dark:border-slate-700">
  <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-700 text-sm text-left">
    <thead class="bg-slate-50 dark:bg-slate-900 text-slate-500 dark:text-slate-400 font-medium">
      <tr>
        <th class="px-4 py-3">ID</th>
        <th class="px-4 py-3">Name</th>
        <th class="px-4 py-3">Status</th>
        <th class="px-4 py-3 text-right">Actions</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-slate-200 dark:divide-slate-700 bg-white dark:bg-slate-800">
      <tr class="hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors">
        <td class="px-4 py-3 font-mono text-xs text-slate-600 dark:text-slate-300">#001</td>
        <td class="px-4 py-3 font-medium text-slate-900 dark:text-white">Example Item</td>
        <td class="px-4 py-3"><span class="px-2 py-1 text-xs font-semibold rounded bg-blue-100 text-blue-800">Valid</span></td>
        <td class="px-4 py-3 text-right">
          <button class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 font-medium">Edit</button>
        </td>
      </tr>
    </tbody>
  </table>
</div>
```

### 3. Accessible Form Field
```html
<div>
  <label for="email" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Email address</label>
  <input type="email" id="email" name="email" class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-lg shadow-sm placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 dark:bg-slate-700 dark:text-white text-sm" placeholder="you@example.com" />
</div>
```
