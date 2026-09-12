<?php

namespace App\Exports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class UsersExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping
{
    public function __construct(private readonly Builder $query) {}

    public function collection(): Collection
    {
        // Same order as the /users page: by name, case-insensitively (LOWER()
        // so SQLite matches MySQL's collation), id breaking ties.
        return $this->query->with('roles')->orderByRaw('LOWER(name) ASC')->orderBy('id')->get();
    }

    public function headings(): array
    {
        // The /users table's columns first, in its order, then the fields the
        // table does not show.
        //
        // Keep this list and map() in the same order — they are positional,
        // so inserting into one alone shifts every later column's data
        // under the wrong heading without any error.
        return [
            'Name',
            'Role',
            'Active',
            'Username',
            'Password',
            'Last Login',
            'Created',
            'Phone',
            'Email',
            'IC Number',
            'Candidate Number',
        ];
    }

    public function map($user): array
    {
        $roleName = $user->roles->first()?->name;

        return [
            $user->name,
            ucfirst($roleName ?? ''),
            $user->is_active ? 'Yes' : 'No',
            $user->username,
            // Only tracked for student users; other roles show blank so
            // staff passwords never leak into the export.
            $roleName === 'student' ? $user->plain_password : null,
            $user->last_login_at?->format('Y-m-d H:i'),
            $user->created_at->format('Y-m-d H:i'),
            $user->phone,
            $user->email,
            $user->ic_number,
            $user->candidate_number,
        ];
    }
}
