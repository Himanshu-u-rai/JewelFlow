/*
 * Public catalog JS bundle.
 *
 * Scope: dedicated to layouts/catalog.blade.php and resources/views/public/catalog/*.
 *
 * Loads ONLY Alpine.js — the public catalog has zero need for Turbo, Flatpickr,
 * or any of the SaaS app's interactive surfaces. Keeping this bundle small
 * keeps the catalog page fast (it's the customer-facing front door).
 */

import Alpine from 'alpinejs';

window.Alpine = Alpine;
Alpine.start();
