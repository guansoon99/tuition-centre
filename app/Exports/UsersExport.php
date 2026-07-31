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
            'Username',
            'Name',
            'Phone',
            'IC Number',
            'Candidate Number',
            'Role',
            'Plain Password',
            'Active',
            'Last Login',
            'Created',
        ];
    }

    public function map($user): array
    {
        $roleName = $user->roles->first()?->name;

        return [
            $user->username,
            $user->name,
            $user->phone,
            $user->ic_number,
            $user->candidate_number,
            ucfirst($roleName ?? ''),
            // Plain password is only tracked for student users; other roles
            // show blank so admin passwords etc. never leak into the export.
            $roleName === 'student' ? $user->plain_password : null,
            $user->is_active ? 'Yes' : 'No',
            $user->last_login_at?->format('Y-m-d H:i'),
            $user->created_at->format('Y-m-d H:i'),
        ];
    }
}
