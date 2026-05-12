<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Material;
use App\Services\SignedUrlService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MaterialController extends Controller
{
    public function download(Request $request, Material $material, SignedUrlService $signer): RedirectResponse
    {
        $this->authorize('download', $material);

        $url = $signer->forMaterial($material, $request->user(), $request);

        return redirect()->away($url);
    }

    /**
     * Local-dev placeholder shown when a seeded material has no real R2 file.
     */
    public function demoPlaceholder(Request $request, Material $material): View
    {
        if (! $request->hasValidSignature()) {
            abort(403);
        }

        return view('student.materials.demo-placeholder', ['material' => $material]);
    }
}
