<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Settings\SettingsCatalogue;
use App\Http\Controllers\Controller;
use App\Support\AuditLogger;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * System settings, and the register of decisions still open.  [API-ADM-40]
 *
 * Every gated client question ships as a row here rather than as a value in the
 * code, so a late answer costs a configuration change instead of rework. Until
 * this screen existed those rows could only be reached through tinker, which
 * meant the client could not answer their own questions and we were guessing on
 * their behalf.
 *
 * **Changing a value and confirming a decision are separate acts.** Confirming
 * the shipped default is a perfectly good answer — "yes, oldest charge first is
 * what we want" — and it has to be recordable as a decision rather than left
 * looking like nobody ever asked. Go-live blocks on the confirmations, not on
 * the values.
 */
class SettingController extends Controller
{
    public function __construct(
        private readonly Settings $settings,
        private readonly SettingsCatalogue $catalogue,
        private readonly AuditLogger $audit,
    ) {}

    /** API-ADM-40. */
    public function index(): Response
    {
        $described = $this->catalogue->all();

        $rows = DB::table('settings as s')
            ->leftJoin('users as u', 'u.id', '=', 's.confirmed_by_user_id')
            ->orderBy('s.key')
            ->get(['s.key', 's.value', 's.type', 's.description', 's.is_gated', 's.confirmed_at', 's.updated_at', 'u.name as confirmed_by'])
            ->filter(fn ($row) => isset($described[$row->key]))
            ->map(function ($row) use ($described) {
                $spec = $described[$row->key];

                return [
                    'key' => $row->key,
                    'value' => (string) $row->value,
                    'group' => $spec['group'],
                    'label' => $spec['label'],
                    'help' => $spec['help'],
                    'input' => $spec['input'],
                    'options' => $spec['options'] ?? null,
                    'min' => $spec['min'] ?? null,
                    'max' => $spec['max'] ?? null,
                    'warning' => $spec['warning'] ?? null,
                    // The Q-number and the spec wording, straight from the
                    // seeded row — it is what the register calls this decision.
                    'reference' => (string) $row->description,
                    'gated' => (bool) $row->is_gated,
                    'confirmed_at' => $row->confirmed_at,
                    'confirmed_by' => $row->confirmed_by,
                    'updated_at' => $row->updated_at,
                ];
            })
            ->values()
            ->all();

        return Inertia::render('Admin/Settings/Index', [
            'settings' => $rows,
            'groups' => array_values(array_unique(array_column($rows, 'group'))),
            // What still blocks go-live. Counted from the database rather than
            // from the catalogue, because a row nobody described is still a row
            // somebody has to answer for.
            'unconfirmed' => $this->settings->unconfirmedGatedKeys(),
        ]);
    }

    /**
     * Change one setting.
     *
     * A key the catalogue does not describe is refused outright. Settings are a
     * small known set; anything else arriving from a form is a bug or an
     * attack, not a new preference.
     */
    public function update(Request $request): RedirectResponse
    {
        $key = (string) $request->input('key');
        $value = (string) $request->input('value', '');

        if (! $this->catalogue->has($key)) {
            throw ValidationException::withMessages(['key' => 'There is no setting by that name.']);
        }

        if (! $this->catalogue->accepts($key, $value)) {
            // The same check the dropdown renders from, so a hand-crafted post
            // cannot set what the screen would not have offered — and an
            // unrecognised allocation order would throw at the next payment
            // rather than here.
            throw ValidationException::withMessages([
                'value' => 'That is not a value this setting accepts.',
            ]);
        }

        $was = (string) DB::table('settings')->where('key', $key)->value('value');

        if ($was === $value) {
            return back()->with('status', 'That is already the setting.');
        }

        $this->settings->set($key, $value);

        $this->audit->record('setting.changed', null, [
            'key' => $key,
            'from' => $was,
            'to' => $value,
        ]);

        return back()->with('status', $this->catalogue->describe($key)['label'].' updated.');
    }

    /**
     * Record that the client has answered a gated question.
     *
     * Separate from update() on purpose: confirming the shipped default is an
     * answer, and one that has to be distinguishable from nobody having asked.
     */
    public function confirm(Request $request): RedirectResponse
    {
        $key = (string) $request->input('key');

        if (! $this->catalogue->has($key)) {
            throw ValidationException::withMessages(['key' => 'There is no setting by that name.']);
        }

        $row = DB::table('settings')->where('key', $key)->first();

        if (! $row?->is_gated) {
            throw ValidationException::withMessages([
                'key' => 'That setting is not a gated decision, so there is nothing to confirm.',
            ]);
        }

        $this->settings->confirm($key, $request->user()->id);

        $this->audit->record('setting.confirmed', null, [
            'key' => $key,
            'value' => (string) $row->value,
        ]);

        return back()->with('status', 'Confirmed. This decision no longer blocks go-live.');
    }
}
