<?php

namespace Nawasara\Cctv\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Camera registry + live view
            'cctv.camera.view',
            'cctv.camera.create',
            'cctv.camera.update',
            'cctv.camera.delete',

            // Recording playback (engine tahap berikutnya, permission disiapkan)
            'cctv.recording.view',
            'cctv.recording.delete',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        $role = Role::where('name', 'developer')->first();

        if ($role) {
            $role->givePermissionTo($permissions);
        }
    }
}
