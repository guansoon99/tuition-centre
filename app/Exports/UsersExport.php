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
        return $this->query->with('roles')->orderByDesc('created_at')->get();
    }

    public function headings(): array
    {
        return [
            'Name',
            'Username',
            'Password',
            'Phone',
            // Keep this list and map() in the same order — they are positional,
            // so inserting into one alone shifts every later column's data
            // under the wrong heading without any error.
            'Email',
            'IC Number',
            'Candidate Number',
            'Role',
            'Active',
            'Last Login',
            'Created',
        ];
    }

    public function map($user): array
    {
        $roleName = $user->roles->first()?->name;

        return [
            $user->name,
            $user->username,
            // Only tracked for student users; other roles show blank so
            // admin passwords etc. never leak into the export.
            $roleName === 'student' ? $user->plain_password : null,
            $user->phone,
            $user->email,
            $user->ic_number,
            $user->candidate_number,
            ucfirst($roleName ?? ''),
            $user->is_active ? 'Yes' : 'No',
            $user->last_login_at?->format('Y-m-d H:i'),
            $user->created_at->format('Y-m-d H:i'),
        ];
    }
}
