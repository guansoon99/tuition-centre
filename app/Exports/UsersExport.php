<?php

namespace App\Exports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class UsersExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(private readonly Builder $query) {}

    public function collection(): Collection
    {
        return $this->query->with('roles')->orderBy('name')->get();
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
            'Active',
            'Last Login',
            'Created',
        ];
    }

    public function map($user): array
    {
        return [
            $user->username,
            $user->name,
            $user->phone,
            $user->ic_number,
            $user->candidate_number,
            ucfirst($user->roles->first()?->name ?? ''),
            $user->is_active ? 'Yes' : 'No',
            $user->last_login_at?->format('Y-m-d H:i'),
            $user->created_at->format('Y-m-d H:i'),
        ];
    }
}
