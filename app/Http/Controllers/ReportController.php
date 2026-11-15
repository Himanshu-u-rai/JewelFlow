<?php

namespace App\Http\Controllers;

class ReportController extends Controller
{
    /**
     * Reports hub — a grouped landing page so a shop owner can find the right
     * report without scanning a long flat nav list. Edition-specific reports are
     * shown only for the relevant edition.
     */
    public function hub()
    {
        $shop = auth()->user()->shop;

        return view('reports.hub', [
            'isRetailer'     => (bool) $shop?->isRetailer(),
            'isManufacturer' => (bool) $shop?->isManufacturer(),
        ]);
    }
}
