<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ContactRequest;
use App\Models\Contact;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ContactController extends Controller
{
    public function index(): View
    {
        return view('admin.contacts.index', [
            'contacts' => Contact::orderBy('sort_order')->orderBy('id')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.contacts.create');
    }

    public function store(ContactRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['is_active'] = true; // Contacts are always active — no toggle in the UI.
        // Auto-append to the end of the existing order.
        $data['sort_order'] = (int) Contact::max('sort_order') + 1;

        Contact::create($data);

        $this->forgetCache();

        return redirect()
            ->route('contacts.index')
            ->with('status', 'Contact added.');
    }

    public function edit(Contact $contact): View
    {
        return view('admin.contacts.edit', ['contact' => $contact]);
    }

    public function update(ContactRequest $request, Contact $contact): RedirectResponse
    {
        $data = $request->validated();
        $data['is_active'] = true;

        $contact->update($data);

        $this->forgetCache();

        return redirect()
            ->route('contacts.index')
            ->with('status', 'Contact updated.');
    }

    public function destroy(Contact $contact): RedirectResponse
    {
        $contact->delete();

        $this->forgetCache();

        return redirect()
            ->route('contacts.index')
            ->with('status', 'Contact deleted.');
    }

    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $valid = Contact::whereIn('id', $data['ids'])->pluck('id')->all();

        DB::transaction(function () use ($data, $valid) {
            $order = 1;
            foreach ($data['ids'] as $id) {
                if (! in_array((int) $id, $valid, true)) {
                    continue;
                }
                Contact::where('id', $id)->update(['sort_order' => $order++]);
            }
        });

        $this->forgetCache();

        return response()->json(['ok' => true, 'count' => count($valid)]);
    }

    private function forgetCache(): void
    {
        Cache::forget('public:contacts');
    }
}
