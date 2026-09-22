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
        $data['is_active'] = true; // New contacts start active; the list has the switch.
        // Auto-append to the end of the existing order.
        $data['sort_order'] = (int) Contact::max('sort_order') + 1;

        Contact::create($data);

        $this->forgetCache();

        return redirect()
            ->route('contacts.index')
            ->with('status', 'Contact added.');
    }

    public function deactivate(Contact $contact): RedirectResponse
    {
        return $this->setActive($contact, false, 'Contact deactivated. Its floating button is hidden.');
    }

    public function activate(Contact $contact): RedirectResponse
    {
        return $this->setActive($contact, true, 'Contact activated.');
    }

    /**
     * Hide or show a contact without deleting it. The floating buttons read
     * active contacts from a cache, so the flip clears it.
     */
    private function setActive(Contact $contact, bool $active, string $status): RedirectResponse
    {
        $contact->update(['is_active' => $active]);

        $this->forgetCache();

        return redirect()
            ->route('contacts.index')
            ->with('status', $status);
    }

    public function edit(Contact $contact): View
    {
        return view('admin.contacts.edit', ['contact' => $contact]);
    }

    public function update(ContactRequest $request, Contact $contact): RedirectResponse
    {
        // Editing keeps the contact's active/inactive state as it is.
        $contact->update($request->validated());

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
